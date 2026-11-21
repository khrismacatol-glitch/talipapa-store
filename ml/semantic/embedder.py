"""LaBSE sentence embeddings through ONNX Runtime.

Reproduces the sentence-transformers/LaBSE pipeline without PyTorch:
BERT encoder (ONNX) -> CLS token -> Dense(768, tanh) -> L2 normalize.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path

import numpy as np
import onnxruntime as ort
from huggingface_hub import snapshot_download
from safetensors.numpy import load_file
from tokenizers import Tokenizer

from .data import MODELS

MODEL_ID = "sentence-transformers/LaBSE"
MAX_TOKENS = 128
CACHE_PATH = MODELS / "labse_text_cache.npz"


class LabseEmbedder:
    def __init__(self, model_id: str = MODEL_ID) -> None:
        path = Path(snapshot_download(model_id, allow_patterns=[
            "onnx/model.onnx", "2_Dense/*", "1_Pooling/config.json",
            "tokenizer.json", "tokenizer_config.json", "special_tokens_map.json",
            "config.json", "modules.json",
        ]))
        self.model_id = model_id
        self.tokenizer = Tokenizer.from_file(str(path / "tokenizer.json"))
        self.tokenizer.enable_truncation(MAX_TOKENS)
        self.tokenizer.enable_padding(pad_id=0, pad_token="[PAD]")
        self.session = ort.InferenceSession(str(path / "onnx" / "model.onnx"), providers=["CPUExecutionProvider"])
        self.input_names = {i.name for i in self.session.get_inputs()}
        dense = load_file(str(path / "2_Dense" / "model.safetensors"))
        self.dense_w = dense["linear.weight"].astype(np.float32)
        self.dense_b = dense["linear.bias"].astype(np.float32)
        pooling = json.loads((path / "1_Pooling" / "config.json").read_text())
        if not pooling.get("pooling_mode_cls_token"):
            raise RuntimeError("Expected CLS pooling for LaBSE")
        self._cache: dict[str, np.ndarray] = {}
        self._load_disk()

    def _encode_batch(self, texts: list[str]) -> np.ndarray:
        encodings = self.tokenizer.encode_batch(texts)
        feeds = {
            "input_ids": np.array([e.ids for e in encodings], dtype=np.int64),
            "attention_mask": np.array([e.attention_mask for e in encodings], dtype=np.int64),
            "token_type_ids": np.array([e.type_ids for e in encodings], dtype=np.int64),
        }
        feeds = {k: v for k, v in feeds.items() if k in self.input_names}
        token_embeddings = self.session.run(None, feeds)[0]
        cls = token_embeddings[:, 0, :]
        out = np.tanh(cls @ self.dense_w.T + self.dense_b)
        return out / np.linalg.norm(out, axis=1, keepdims=True)

    def _load_disk(self) -> None:
        if not CACHE_PATH.exists():
            return
        data = np.load(CACHE_PATH, allow_pickle=True)
        keys = data["keys"]
        vecs = data["vecs"]
        self._cache = {str(k): vecs[i] for i, k in enumerate(keys)}

    def _save_disk(self) -> None:
        if not self._cache:
            return
        MODELS.mkdir(parents=True, exist_ok=True)
        keys = np.array(list(self._cache.keys()))
        vecs = np.stack([self._cache[k] for k in keys])
        np.savez_compressed(CACHE_PATH, keys=keys, vecs=vecs)

    def encode(self, texts: list[str], batch_size: int = 64) -> np.ndarray:
        keys = [hashlib.sha1(t.encode("utf-8")).hexdigest() for t in texts]
        missing = [i for i, k in enumerate(keys) if k not in self._cache]
        for start in range(0, len(missing), batch_size):
            idx = missing[start:start + batch_size]
            vectors = self._encode_batch([texts[i] for i in idx])
            for i, v in zip(idx, vectors):
                self._cache[keys[i]] = v.astype(np.float32, copy=False)
        if missing:
            self._save_disk()
        if not texts:
            return np.zeros((0, self.dense_w.shape[0]), dtype=np.float32)
        return np.stack([self._cache[k] for k in keys])
