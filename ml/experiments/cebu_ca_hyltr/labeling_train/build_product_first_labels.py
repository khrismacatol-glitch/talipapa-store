"""Product-first provisional train labels. Does not touch the blank official sheet."""
from __future__ import annotations

import csv
import json
from collections import Counter, defaultdict
from pathlib import Path

import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[3]))
from semantic.data import ROOT, normalize_query

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
OUT = EXPERIMENT / "labeling_train"


def load_catalog():
    products = json.loads((EXPERIMENT / "products.json").read_text(encoding="utf-8"))
    cat = {}
    for p in products:
        specs = p.get("specs") if p.get("specs") is not None else {}
        cat[p["key"]] = {
            "key": p["key"],
            "name": p.get("name") or "",
            "desc": (p.get("description") or ""),
            "desc_l": (p.get("description") or "").lower(),
            "name_l": (p.get("name") or "").lower(),
            "category": p.get("category") or "",
            "has_specs": p.get("specs") is not None,
            "is_milk": bool(specs.get("is_milk")) if p.get("specs") is not None else False,
            "source": specs.get("source"),
            "form": specs.get("form"),
            "fat": specs.get("fat"),
            "sugar": specs.get("sugar"),
            "organic": specs.get("organic") if p.get("specs") is not None else None,
            "volume_ml": specs.get("volume_ml"),
            "brand": (specs.get("brand") or "") or "",
        }
    return products, cat


def groups(cat):
    keys = list(cat)
    cow_liq = [
        k for k, p in cat.items()
        if p["is_milk"] and p["source"] == "cow" and p["form"] != "powder"
    ]
    cow_plain = [k for k in cow_liq if k != "nesquik-chocolate-milk-drink-180ml"]
    cow_full = [k for k in cow_plain if cat[k]["fat"] == "full"]
    cow_low = [k for k in cow_plain if cat[k]["fat"] == "low"]
    cow_non = [k for k, p in cat.items() if p["is_milk"] and p["source"] == "cow" and p["fat"] == "non"]
    powders = [k for k, p in cat.items() if p["form"] == "powder"]
    cow_powders = [k for k in powders if cat[k]["source"] == "cow"]
    plant = [k for k, p in cat.items() if p["source"] == "plant"]
    animal_other = [k for k, p in cat.items() if p["source"] in {"carabao", "goat"}]
    organic_dairy = [
        k for k, p in cat.items()
        if p["is_milk"] and p["organic"] is True and p["source"] in {"cow", "carabao"}
    ]
    organic_cow = [
        k for k, p in cat.items()
        if p["is_milk"] and p["organic"] is True and p["source"] == "cow"
    ]
    organic_plant = [
        k for k, p in cat.items()
        if p["is_milk"] and p["organic"] is True and p["source"] == "plant"
    ]
    organic_milk = [
        k for k, p in cat.items() if p["is_milk"] and p["organic"] is True
    ]
    v1000 = [k for k in cow_plain if cat[k]["volume_ml"] == 1000]
    v946 = [k for k in cow_plain if cat[k]["volume_ml"] == 946]
    sugar_unsweet = [k for k, p in cat.items() if p["is_milk"] and p["sugar"] == "unsweetened"]
    sugar_no_added = [k for k, p in cat.items() if p["is_milk"] and p["sugar"] == "no_added"]
    sugar_free_txt = [
        k for k, p in cat.items()
        if p["is_milk"] and ("sugar free" in p["desc_l"] or "sugar-free" in p["desc_l"] or "sugar free" in p["name_l"])
    ]
    unsweet = sugar_unsweet + sugar_no_added
    alaska_drink = [k for k, p in cat.items() if p["brand"] == "alaska" and p["is_milk"]]
    alaska_cook = [k for k, p in cat.items() if p["brand"] == "alaska" and not p["is_milk"]]
    nestle_fresh = [
        k for k, p in cat.items()
        if p["brand"] == "nestle" and "fresh milk" in p["name_l"]
    ]
    return {
        "all": keys,
        "cow_liq": cow_liq,
        "cow_plain": cow_plain,
        "cow_full": cow_full,
        "cow_low": cow_low,
        "cow_non": cow_non,
        "powders": powders,
        "cow_powders": cow_powders,
        "plant": plant,
        "animal_other": animal_other,
        "organic_dairy": organic_dairy,
        "organic_cow": organic_cow,
        "organic_plant": organic_plant,
        "organic_milk": organic_milk,
        "v1000": v1000,
        "v946": v946,
        "sugar_unsweet": sugar_unsweet,
        "sugar_no_added": sugar_no_added,
        "sugar_free_txt": sugar_free_txt,
        "unsweet": unsweet,
        "alaska_drink": alaska_drink,
        "alaska_cook": alaska_cook,
        "nestle_fresh": nestle_fresh,
        "nesquik": "nesquik-chocolate-milk-drink-180ml",
        "glucerna": "glucerna-diabetes-nutrition-milk-400g",
        "anlene": "anlene-gold-no-added-sugar-milk-powder-600g",
        "bear": "bear-brand-fortified-powdered-milk-320g",
        "anchor": "anchor-skim-milk-powder-400g",
        "silk": "silk-unsweetened-soy-milk-946ml",
        "almond": "almond-breeze-unsweetened-almond-milk-946ml",
        "oat_gb": "green-basket-organic-unsweetened-oat-milk-1l",
        "oat_n": "nestle-vegan-oat-milk-300ml",
        "arla_lf": "arla-lactose-free-semi-skimmed-milk-1l",
        "meiji": "meiji-whole-milk-946ml",
        "alaska_fresh": "alaska-fresh-full-cream-milk-1l",
        "ov": "organic-valley-whole-milk-1l",
        "ff": "farm-fresh-organic-carabao-milk-500ml",
        "evap": "alaska-evaporada-creamer-370ml",
        "cond": "alaska-sweetened-condensed-milk-300ml",
        "cm": "coffee-mate-original-creamer-450g",
        "gata": "ufc-coconut-milk-gata-400ml",
        "butter": "magnolia-butter-salted-225g",
        "eden": "eden-cheese-165g",
        "tapa": "beef-tapa-karne-sa-baka-500g",
        "rice_br": "organic-brown-rice-2kg",
        "rice_j": "jasmine-rice-5kg",
        "crackers": "low-fat-whole-wheat-crackers-250g",
        "wafers": "sugar-free-wafers-for-diabetics-150g",
        "tea": "wintermelon-milk-tea-500ml",
        "ice": "selecta-cookies-and-cream-ice-cream-14l",
        "cadbury": "cadbury-dairy-milk-chocolate-bar-165g",
        "alaska": [k for k, p in cat.items() if p["brand"] == "alaska"],
        "nestle": [k for k, p in cat.items() if p["brand"] == "nestle"],
        "vegan_txt": [
            k for k, p in cat.items()
            if "vegan" in p["desc_l"] or "vegan" in p["name_l"]
        ],
        "semi": [
            k for k, p in cat.items()
            if "1.5%" in p["desc"] or "semi-skimmed" in p["desc_l"] or "semi skimmed" in p["desc_l"]
        ],
    }


def fill(keys, g2=(), g1=(), default=0):
    g = {k: default for k in keys}
    for k in g1:
        g[k] = 1
    for k in g2:
        g[k] = 2
    return g


def milk_related(g, cat, extra_1=()):
    """Drinking/powder/plant/cooking dairy as 1 if still 0 — used sparingly."""
    for k, p in cat.items():
        if g[k] != 0:
            continue
        if p["is_milk"] or k in extra_1:
            g[k] = 1
    return g


def label_one(qraw: str, cat, G) -> tuple[dict, str, str]:
    """Returns grades, product_class, ambiguous_note (empty if none)."""
    q = normalize_query(qraw)
    keys = G["all"]
    K = G

    def z():
        return {k: 0 for k in keys}

    # --- no catalog class ---
    none = {
        "toyo": "soy sauce, not soy milk",
        "itlog": "eggs not in catalog",
        "yogurt": "yogurt not in catalog",
        "sabon": "soap not in catalog",
        "sardinas": "sardines not in catalog",
        "asukal": "sugar pack not in catalog",
        "1 litro nga coke": "cola not in catalog",
        "presko nga isda": "isda=fish, not fresh milk",
        "asin ug asukal": "salt/sugar not in catalog",
    }
    if q in none:
        return z(), "none_in_catalog", ""

    # --- grocery exact (class first) ---
    grocery = {
        "butter": (K["butter"], "butter"),
        "milk tea": (K["tea"], "milk_tea"),
        "milk tea nga wintermelon": (K["tea"], "milk_tea"),
        "gata sa lubi": (K["gata"], "coconut_gata"),
        "keso nga eden": (K["eden"], "cheese"),
        "biskwit para sa diabetic": (K["wafers"], "biscuits"),
        "tapa sa baka": (K["tapa"], "beef"),
        "karne sa baka": (K["tapa"], "beef"),
        "karne sa baka para sa sinugba": (K["tapa"], "beef"),
        "organic brown rice": (K["rice_br"], "rice"),
        "low fat crackers": (K["crackers"], "crackers"),
        "coffee creamer": (K["cm"], "creamer"),
        "condensed milk": (K["cond"], "cooking_dairy"),
        "condensada nga gatas": (K["cond"], "cooking_dairy"),
        "gatas nga condensed": (K["cond"], "cooking_dairy"),
        "gatas nga evaporated": (K["evap"], "cooking_dairy"),
    }
    if q in grocery:
        exact, cls = grocery[q]
        g = z()
        g[exact] = 2
        if cls == "rice":
            g[K["rice_j"]] = 1
        elif cls == "cooking_dairy":
            other = K["evap"] if exact == K["cond"] else K["cond"]
            g[other] = 1
            g[K["cm"]] = 1
        elif cls == "creamer":
            g[K["evap"]] = 1
        elif cls == "coconut_gata":
            pass
        elif cls == "beef":
            pass
        return g, cls, ""

    if q == "kape nga 3 in 1":
        g = z()
        g[K["cm"]] = 1
        g[K["evap"]] = 1
        return g, "coffee_mix_absent", "No 3-in-1 mix in catalog; Coffee-Mate/evaporada are related (1), not 2."

    # --- flavored / missing brand ---
    if q == "chuckie nga chocolate milk":
        g = z()
        g[K["nesquik"]] = 1
        return g, "chocolate_milk_brand_absent", ""
    if q in {"gatas nga banana flavor", "gatas nga strawberry flavor"}:
        g = z()
        g[K["nesquik"]] = 1
        return g, "flavor_absent", ""
    if q == "gatas nga vanilla para sa adulto":
        g = z()
        g[K["anlene"]] = 1
        return g, "vanilla_absent", ""
    if q in {"naay flavor nga gatas", "gatas nga chocolate flavor", "gusto kog gatas nga chocolate"}:
        g = fill(keys, g2=[K["nesquik"]], g1=K["cow_plain"] + K["powders"])
        g[K["cadbury"]] = 0
        return g, "flavored_chocolate_drink", ""

    # --- brands ---
    if q == "bear brand nga gatas":
        return fill(keys, g2=[K["bear"]], g1=[x for x in K["powders"] if x != K["bear"]]), "powder_brand", ""
    if q == "anchor nga gatas":
        return fill(keys, g2=[K["anchor"]], g1=[x for x in K["powders"] if x != K["anchor"]]), "powder_brand", ""
    if q == "anlene nga gatas":
        return fill(keys, g2=[K["anlene"]], g1=[x for x in K["powders"] if x != K["anlene"]]), "powder_brand", ""
    if q == "alaska nga gatas":
        g = fill(
            keys,
            g2=K["alaska_drink"],
            g1=K["alaska_cook"] + K["cow_plain"] + K["cow_powders"] + [K["gata"]],
        )
        return g, "alaska_drinking_milk", ""
    if q in {"nestlé nga fresh milk", "nestle nga fresh milk"}:
        g = fill(
            keys,
            g2=K["nestle_fresh"],
            g1=K["cow_plain"] + K["cow_powders"],
        )
        g["nestle-non-fat-skim-milk-1l"] = 0
        g["nestle-vegan-oat-milk-300ml"] = 0
        return g, "nestle_fresh", ""

    # --- plant ---
    if q in {"unsweetened soy milk", "soya milk"}:
        g1 = [k for k in K["plant"] if k != K["silk"]]
        return fill(keys, g2=[K["silk"]], g1=g1), "soy_drink", ""
    if q == "zero sugar almond milk":
        g1 = [k for k in K["plant"] if k != K["almond"]]
        return fill(keys, g2=[K["almond"]], g1=g1), "almond_drink", ""
    if q in {"gatas nga gikan sa tanom", "naa moy gatas nga dili gikan sa hayop?"}:
        return fill(keys, g2=K["plant"], g1=K["cow_plain"] + K["powders"] + [K["gata"]]), "plant_drink", ""
    if q == "gatas nga vegan pero dili chocolate":
        g = fill(keys, g2=K["vegan_txt"], g1=[K["almond"], K["silk"]])
        g[K["nesquik"]] = 0
        g[K["cadbury"]] = 0
        return g, "vegan_drink", ""

    # --- lactose / holstein / usda ---
    if q == "gatas nga walay lactose":
        return fill(keys, g2=[K["arla_lf"]], g1=K["cow_plain"] + K["powders"]), "lactose_free", ""
    if q == "holstein cow fresh milk":
        return fill(keys, g2=[K["meiji"]], g1=K["cow_full"]), "holstein", ""
    if q == "usda organic milk":
        return fill(keys, g2=[K["ov"]], g1=K["organic_milk"]), "usda_organic", ""

    # --- powder form ---
    powder_q = {
        "powdered cow milk", "gatas nga pulbos", "gatas na pulbos", "gatas sa pulbos",
    }
    if q in powder_q:
        g2 = [k for k in K["powders"] if k != K["glucerna"]]
        g = fill(keys, g2=g2, g1=K["cow_plain"] + [K["glucerna"]])
        g[K["glucerna"]] = 1
        return g, "powdered_milk", ""
    if q == "powdered cow milk in a tin":
        return fill(keys, g1=K["powders"]), "powder_tin_unverified", ""
    if q == "diabetic milk powder":
        return fill(keys, g2=[K["glucerna"]], g1=[x for x in K["powders"] if x != K["glucerna"]]), "diabetic_powder", ""
    if q == "gatas nga 300 grams":
        return fill(keys, g1=K["powders"]), "size_300g_absent", ""
    if q == "gatas nga 680 grams":
        return fill(keys, g1=K["powders"]), "size_680g_absent", ""
    if q == "gatas nga 4.8 kilos":
        return fill(keys, g1=K["powders"]), "size_4_8kg_absent", ""

    # --- infant / kids (unsupported) ---
    if q in {
        "gatas nga pang kids", "gatas para sa 1 year old", "gatas para sa newborn",
        "gatas nga para sa bata pero dili pangbaby",
        "uht cow milk for kids",
    }:
        g = fill(keys, g1=K["powders"] + K["cow_plain"])
        if q == "uht cow milk for kids":
            g[K["alaska_fresh"]] = 1
            return g, "uht_kids", ""
        return g, "infant_or_kids_absent", ""

    if q in {"gatas para sa tigulang", "gatas para sa edaran", "naa moy gatas nga pang tigulang?"}:
        return fill(keys, g2=[K["anlene"]], g1=[K["glucerna"]] + K["powders"] + K["cow_plain"]), "adult_powder", ""

    # --- sizes 1L ---
    if q in {"pure cow milk 1 liter", "fresh milk ng baka 1 litro"}:
        g = fill(keys, g2=K["v1000"], g1=K["v946"] + K["animal_other"] + K["powders"])
        return g, "cow_milk_1l", ""

    # --- organic / chemicals ---
    organic_fresh = {"organic na fresh milk", "natural at organic na fresh milk"}
    organic_generic = {
        "milk from organic farm", "gatas nga walay kemikal", "gatas na walang kemikal",
        "natural nga gatas walay pestisidyo", "milk without pesticides and chemicals",
    }
    if q in organic_fresh:
        g = fill(
            keys,
            g2=K["organic_cow"] + [k for k in K["organic_dairy"] if cat[k]["source"] == "carabao"],
            g1=K["organic_plant"] + K["cow_plain"] + K["cow_powders"],
        )
        # "fresh milk" is dairy; plant organic is not cow/fresh milk (1)
        return g, "organic_fresh_dairy", ""
    if q in organic_generic:
        g = fill(
            keys,
            g2=K["organic_milk"],
            g1=K["cow_plain"] + K["cow_powders"],
        )
        return g, "organic_milk_generic", ""

    # --- sugar (G: distinguish unsweetened / no-added / sugar-free) ---
    if q in {"unsweetened milk", "unsweetened nga gatas"}:
        g = fill(
            keys,
            g2=K["sugar_unsweet"],
            g1=K["sugar_no_added"] + K["cow_plain"] + K["cow_powders"],
        )
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        return g, "unsweetened_milk", ""
    if q in {
        "gatas nga no sugar added", "no sugar added milk", "gatas nga walay dugang nga asukal",
    }:
        g = fill(
            keys,
            g2=K["sugar_no_added"],
            g1=K["sugar_unsweet"] + K["cow_plain"] + K["cow_powders"],
        )
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        return g, "no_added_sugar_milk", ""
    if q in {"sugar free milk", "sugar free na gatas", "sugar free nga gatas"}:
        g = fill(
            keys,
            g2=K["sugar_free_txt"],
            g1=K["sugar_unsweet"] + K["sugar_no_added"] + K["cow_plain"] + K["cow_powders"],
        )
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        g[K["wafers"]] = 0
        return g, "sugar_free_milk", ""
    if q in {"gatas nga walay asukal", "gatas na walang asukal"}:
        g2 = list(dict.fromkeys(K["sugar_unsweet"] + K["sugar_free_txt"]))
        g = fill(
            keys,
            g2=g2,
            g1=K["sugar_no_added"] + K["cow_plain"] + K["cow_powders"],
        )
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        g[K["wafers"]] = 0
        return g, "no_sugar_cebuano", ""
    if q in {"gatas nga dili tam-is", "gatas na hindi matamis"}:
        g = fill(
            keys,
            g2=K["sugar_unsweet"],
            g1=K["sugar_no_added"] + K["sugar_free_txt"] + K["cow_plain"] + K["cow_powders"],
        )
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        g[K["wafers"]] = 0
        g[K["glucerna"]] = 1
        return g, "not_sweet_taste", ""
    if q == "gatas nga ubos ang asukal":
        g = fill(keys, g1=K["sugar_unsweet"] + K["sugar_no_added"] + K["cow_plain"])
        g[K["nesquik"]] = 0
        g[K["cond"]] = 0
        return g, "low_sugar_unspecified", "No low-sugar spec; no Grade 2."

    diabetic_use = {
        "gatas para sa may diabetes", "gatas para sa taas og sugar sa dugo", "gatas para sa diabetiko",
        "gatas para sa mga diabetic", "gatas para sa diabetic", "gatas para sa mga may diabetes",
        "diabetic nga gatas", "milk for diabetics", "gatas para sa naay diabetes",
        "mangita ko og gatas para sa diabetic", "gatas para sa mataas ang blood sugar",
        "gatas para sa may sakit sa asukal", "gatas para sa may diabetes na lolo",
        "gatas para sa lolo nga diabetic", "milk safe for diabetes",
        "milk for people with high blood sugar", "gatas nga dili makataas sa sugar",
        "gatas para sa mga diabetic nga walay asukal",
    }
    if q in diabetic_use:
        g = fill(keys, g2=[K["glucerna"]], g1=K["unsweet"] + K["powders"] + K["cow_plain"])
        g[K["wafers"]] = 0
        return g, "diabetic_nutrition_milk", ""

    # --- fat ---
    nonfat_q = {
        "gatas nga walay tambok", "skim nga gatas", "non fat milk", "fat free milk",
        "gatas na walang taba", "non-fat nga gatas", "skim milk nga walay tambok", "gatas nga non-fat",
    }
    if q in nonfat_q:
        g2 = [k for k, p in cat.items() if p["is_milk"] and p["fat"] == "non"]
        g1 = K["cow_low"] + [K["anlene"], K["glucerna"]] + [
            k for k in K["plant"] if cat[k]["fat"] == "low"
        ]
        return fill(keys, g2=g2, g1=g1), "nonfat_milk", ""

    lowfat_q = {
        "gatas nga gamay ra og tambok", "reduced fat milk", "semi skimmed milk",
        "low fat na gatas para pumayat", "gatas nga gamay ang tambok",
        "gatas nga gaan ra ang tambok para sa diet",
        "naa moy gatas nga dili kaayo tambok?",
        "gatas nga gikan sa baka pero dili full cream",
    }
    if q in lowfat_q:
        if q == "semi skimmed milk":
            g2 = list(K["semi"])
            g1 = [k for k in K["cow_low"] + K["cow_non"] if k not in g2]
            return fill(keys, g2=g2, g1=g1), "semi_skimmed", ""
        if q == "gatas nga gikan sa baka pero dili full cream":
            g2 = [
                k for k, p in cat.items()
                if p["is_milk"] and p["source"] == "cow" and p["fat"] in {"low", "non"}
                and k != K["glucerna"]
            ]
            g1 = K["cow_full"] + K["plant"] + [K["glucerna"]]
            g = fill(keys, g2=g2, g1=g1)
            g[K["glucerna"]] = 1
            return g, "cow_not_full_cream", ""
        g2 = list(K["cow_low"])
        g1 = K["cow_non"] + [K["anlene"], K["glucerna"]] + [
            k for k in K["plant"] if cat[k]["fat"] in {"low", "non"}
        ]
        return fill(keys, g2=g2, g1=g1), "low_fat_cow_milk", ""

    health = {
        "gatas nga dili makapatambok", "gatas para sa nag diet", "gatas para sa gusto mo niwang",
        "light milk for weight loss", "milk for dieting low calorie", "gatas para sa nagda-diet",
        "gatas na hindi nakakataba", "gatas nga dili makataba", "gatas nga dili kaayo makataba",
        "gatas nga dili makataba para sa diet", "gatas dili makataba", "gatas na hindi makataba",
        "gatas para dili motaas ang timbang", "gatas para sa nagpaniwang",
        "gatas nga dili makadugang og timbang",
    }
    if q in health:
        g = fill(keys, g1=K["cow_plain"] + K["powders"] + K["plant"])
        return g, "health_claim_milk", ""

    # --- cooking / dessert / coffee / cereal ---
    if q in {"pangluto nga gatas", "mangita kog gatas nga pangluto og sopas", "gatas para sa sopas"}:
        return fill(keys, g2=[K["evap"]], g1=[K["cond"], K["gata"]] + K["cow_plain"]), "cooking_milk", ""
    if q == "gatas nga pangluto pero dili condensed":
        g = fill(keys, g2=[K["evap"]], g1=[K["gata"]] + K["cow_plain"])
        g[K["cond"]] = 0
        return g, "cooking_not_condensed", ""
    if q == "gatas nga thick creamer":
        g = fill(keys, g1=[K["evap"], K["cond"], K["cm"]])
        return g, "thick_creamer", ""
    dessert = {
        "gatas para sa mango float", "gatas para sa fruit salad", "gatas para sa leche flan",
        "gatas para sa halo-halo", "gatas para sa champorado", "kanang gatas nga panghimog dessert",
        "gatas para sa dessert nga tam-is ug creamy",
    }
    if q in dessert:
        return fill(keys, g2=[K["cond"]], g1=[K["evap"], K["ice"]] + K["cow_plain"]), "dessert_condensed", ""
    if q == "gatas para sa dessert pero dili fresh milk":
        g = fill(keys, g2=[K["cond"]], g1=[K["evap"], K["ice"]])
        return g, "dessert_not_fresh", ""
    if q == "gatas para sa cereal":
        return fill(keys, g2=K["cow_plain"], g1=K["powders"] + K["plant"] + [K["nesquik"]]), "cereal_drinking_milk", ""
    if q == "mangita kog gatas para sa akong kape":
        g = fill(keys, g2=K["cow_plain"] + K["animal_other"], g1=[K["evap"], K["cm"]] + K["plant"] + K["cow_powders"])
        g[K["cm"]] = 1
        g[K["nesquik"]] = 1
        return g, "milk_for_coffee", ""

    # --- pack ---
    if q == "gatas nga naa sa botelya":
        return fill(keys, g1=K["cow_plain"] + K["plant"] + [K["nesquik"]]), "bottle_unverified", ""
    if q == "gatas nga naa sa karton":
        return fill(keys, g1=K["cow_plain"] + K["plant"] + [K["nesquik"]]), "carton_unverified", ""
    if q == "gatas nga naa sa lata":
        g = fill(keys, g2=[K["evap"], K["cond"]], g1=K["powders"])
        return g, "canned_cooking", ""

    # --- probiotic ---
    if q in {"gatas nga probiotic", "kanang gatas nga naay probiotics"}:
        return fill(keys, g1=K["cow_plain"] + K["powders"] + K["plant"]), "probiotic_absent", ""

    # --- fresh / liquid / drinkable ---
    if q == "gatas nga fresh pero dili powdered":
        g = fill(keys, g2=K["cow_plain"] + K["animal_other"], g1=K["plant"])
        for k in K["powders"]:
            g[k] = 0
        return g, "fresh_not_powder", ""
    if q == "gatas nga fresh pero dili low fat":
        g2 = [k for k in K["cow_full"] + K["animal_other"]]
        g = fill(keys, g2=g2, g1=K["cow_low"] + K["cow_non"] + K["plant"])
        for k in K["cow_low"] + [x for x in K["cow_non"] if cat[x]["form"] != "powder"]:
            g[k] = 1
        for k in K["cow_non"]:
            if cat[k]["form"] == "powder":
                g[k] = 0
        return g, "fresh_not_low_fat", ""
    if q in {
        "full cream fresh milk", "gatas nga fresh", "naa moy gatas nga fresh?",
    }:
        g2 = K["cow_full"] + K["animal_other"]
        # include named Fresh low-fat? Query says full cream OR just fresh
        if q != "full cream fresh milk":
            g2 = K["cow_plain"] + K["animal_other"]
        g = fill(keys, g2=g2, g1=K["powders"] + K["plant"])
        if q == "full cream fresh milk":
            for k in K["cow_low"] + K["cow_non"]:
                g[k] = 1 if cat[k]["form"] != "powder" else 1
            g[K["nesquik"]] = 1
        return g, "fresh_drinking_milk", ""

    if q == "whole milk from cows":
        g2 = K["cow_full"] + [k for k in K["cow_powders"] if cat[k]["fat"] == "full"]
        g1 = K["cow_low"] + K["cow_non"] + [k for k in K["cow_powders"] if cat[k]["fat"] != "full"] + K["animal_other"]
        return fill(keys, g2=g2, g1=g1), "whole_cow_milk", ""

    if q in {
        "gatas nga liquid", "gatas nga mainom", "gatas nga mainom dretso",
        "gatas nga pwede imnon kada adlaw", "kanang gatas nga pwede diretso imnon",
    }:
        return fill(keys, g2=K["cow_plain"] + K["animal_other"] + K["plant"], g1=K["powders"] + [K["nesquik"]]), "drinking_liquid", ""

    # --- generic cow dairy ---
    generic_cow = {
        "gusto ko og gatas nga gikan sa baka", "puro nga gatas sa among baka",
        "regular dairy milk", "palit ko og gatas nga gikan sa baka",
        "real cow milk not plant based",
    }
    if q in generic_cow:
        cow_powders_regular = [k for k in K["cow_powders"] if k != K["glucerna"]]
        g = fill(
            keys,
            g2=K["cow_plain"] + cow_powders_regular,
            g1=K["animal_other"] + [K["evap"], K["cond"], K["glucerna"]],
        )
        g[K["nesquik"]] = 1
        g[K["glucerna"]] = 1
        if q == "real cow milk not plant based":
            for k in K["plant"] + [K["gata"]]:
                g[k] = 0
        return g, "cow_milk", ""

    # fallback: should not happen
    return fill(keys, g1=K["cow_plain"] + K["powders"]), "UNMAPPED", "UNMAPPED QUERY — human must label."


def checks(rows, cat, G):
    issues = []
    for rec in rows:
        q = rec["query"]
        g = rec["grades"]
        cls = rec["class"]
        nq = normalize_query(q)
        g2 = [k for k, v in g.items() if v == 2]
        # category mismatches
        if cls == "rice":
            for k in g2:
                if k not in {G["rice_br"]}:
                    issues.append((q, "category_mismatch", cat[k]["name"]))
            if G["rice_br"] not in g2:
                issues.append((q, "missing_grade2", "Organic Brown Rice 2kg"))
        if cls == "crackers":
            for k in g2:
                if k != G["crackers"]:
                    issues.append((q, "category_mismatch", cat[k]["name"]))
            if g[G["crackers"]] != 2:
                issues.append((q, "missing_grade2", "crackers"))
        if cls == "beef" and g.get(G["tapa"]) != 2:
            issues.append((q, "missing_grade2", "Beef Tapa"))
        if cls == "none_in_catalog" and g2:
            issues.append((q, "false_grade2_no_class", ",".join(g2)))
        if "isda" in nq and g2:
            issues.append((q, "false_grade2_fish", str(g2)))
        if nq == "toyo" and g.get(G["silk"]) != 0:
            issues.append((q, "toyo_not_silk", str(g[G["silk"]])))
        if "dili condensed" in nq and g.get(G["cond"]) == 2:
            issues.append((q, "exclusion_violated", "condensed"))
        if "dili powdered" in nq or "dili powder" in nq:
            for k in g2:
                if cat[k]["form"] == "powder":
                    issues.append((q, "exclusion_violated", cat[k]["name"]))
        if "not plant" in nq:
            for k in g2:
                if cat[k]["source"] == "plant":
                    issues.append((q, "exclusion_violated", cat[k]["name"]))
        if "dili chocolate" in nq and G["nesquik"] in g2:
            issues.append((q, "exclusion_violated", "Nesquik"))
        if "dili full cream" in nq:
            for k in g2:
                if cat[k]["fat"] == "full":
                    issues.append((q, "exclusion_violated", cat[k]["name"]))
        if cls == "soy_drink":
            for k in g2:
                if k != G["silk"]:
                    issues.append((q, "brand_or_source_mismatch", cat[k]["name"]))
        if cls == "powder_brand" and "bear brand" in nq:
            if g2 != [G["bear"]] and set(g2) != {G["bear"]}:
                extra = [k for k in g2 if k != G["bear"]]
                if extra:
                    issues.append((q, "brand_mismatch", str(extra)))
        if cls == "cow_milk_1l":
            for k in g2:
                if cat[k]["volume_ml"] != 1000 or cat[k]["source"] != "cow" or cat[k]["form"] == "powder":
                    issues.append((q, "size_or_source_mismatch", cat[k]["name"]))
        if cls == "cow_milk":
            for k in g2:
                if cat[k]["source"] != "cow" or k == G["nesquik"] or k == G["glucerna"]:
                    issues.append((q, "generic_cow_mismatch", cat[k]["name"]))
            for k in G["cow_powders"]:
                if k == G["glucerna"]:
                    if g.get(k) == 2:
                        issues.append((q, "glucerna_generic_cow_is_2", cat[k]["name"]))
                    elif g.get(k) != 1:
                        issues.append((q, "glucerna_generic_cow_not_1", str(g.get(k))))
                elif g.get(k) != 2:
                    issues.append((q, "cow_powder_not_downgraded", cat[k]["name"]))
        if cls == "powdered_milk":
            if g.get(G["glucerna"]) == 2:
                issues.append((q, "glucerna_ordinary_powder_is_2", "glucerna"))
            elif g.get(G["glucerna"]) != 1:
                issues.append((q, "glucerna_ordinary_powder_not_1", str(g.get(G["glucerna"]))))
            for k in G["cow_powders"]:
                if k != G["glucerna"] and g.get(k) != 2:
                    issues.append((q, "ordinary_cow_powder_not_2", cat[k]["name"]))
        if cls == "cow_not_full_cream":
            if g.get(G["glucerna"]) == 2:
                issues.append((q, "glucerna_not_full_cream_is_2", "glucerna"))
            for k in g2:
                if cat[k]["fat"] == "full" or k == G["glucerna"]:
                    issues.append((q, "not_full_cream_mismatch", cat[k]["name"]))
        if cls in {"diabetic_nutrition_milk", "diabetic_powder", "sugar_free_milk"}:
            if g.get(G["glucerna"]) != 2:
                issues.append((q, "glucerna_supported_claim_not_2", f"{cls}={g.get(G['glucerna'])}"))
        if cls == "no_sugar_cebuano":
            for k in g2:
                if k not in G["sugar_unsweet"] and k not in G["sugar_free_txt"]:
                    issues.append((q, "walay_asukal_g2_not_unsweet_or_sugarfree", cat[k]["name"]))
            if g.get("anlene-gold-no-added-sugar-milk-powder-600g") == 2:
                issues.append((q, "no_added_as_2_without_sugar_free", "anlene"))
            if g.get("nestle-fresh-milk-no-sugar-added-1l") == 2:
                issues.append((q, "no_added_as_2_without_sugar_free", "nsa"))
        if cls == "not_sweet_taste":
            for k in g2:
                if cat[k]["sugar"] != "unsweetened":
                    issues.append((q, "not_sweet_g2_not_unsweetened", cat[k]["name"]))
            if g.get(G["glucerna"]) == 2:
                issues.append((q, "sugar_free_assumed_not_sweet", "glucerna"))
        if cls == "milk_for_coffee":
            if g.get(G["evap"]) == 2 or g.get(G["cm"]) == 2:
                issues.append((q, "creamer_as_gatas_2", "evaporada/coffee-mate"))
            if g.get(G["evap"]) != 1 or g.get(G["cm"]) != 1:
                issues.append((q, "creamer_not_1", f"evap={g.get(G['evap'])} cm={g.get(G['cm'])}"))
        if cls in {"low_sugar_unspecified", "coffee_mix_absent"} and g2:
            issues.append((q, "should_have_no_grade2", cls))
        if cls == "unsweetened_milk":
            for k in g2:
                if cat[k]["sugar"] != "unsweetened":
                    issues.append((q, "sugar_claim_mismatch", cat[k]["name"]))
        if cls == "no_added_sugar_milk":
            for k in g2:
                if cat[k]["sugar"] != "no_added":
                    issues.append((q, "sugar_claim_mismatch", cat[k]["name"]))
        if cls == "sugar_free_milk":
            for k in g2:
                if k not in G["sugar_free_txt"]:
                    issues.append((q, "sugar_claim_mismatch", cat[k]["name"]))
        if cls == "organic_milk_generic":
            for k in G["organic_plant"]:
                if g.get(k) != 2:
                    issues.append((q, "organic_plant_not_2_generic", cat[k]["name"]))
        if cls == "organic_fresh_dairy":
            for k in G["organic_plant"]:
                if g.get(k) == 2:
                    issues.append((q, "organic_plant_on_fresh_dairy", cat[k]["name"]))
        if cls == "alaska_drinking_milk":
            for k in g2:
                if cat[k]["brand"] != "alaska" or not cat[k]["is_milk"]:
                    issues.append((q, "alaska_not_by_type", cat[k]["name"]))
            for k in G["alaska_cook"]:
                if g.get(k) == 2:
                    issues.append((q, "alaska_cooking_as_2", cat[k]["name"]))
        if cls == "nestle_fresh":
            for k in g2:
                if "fresh milk" not in cat[k]["name_l"] or cat[k]["brand"] != "nestle":
                    issues.append((q, "nestle_not_fresh", cat[k]["name"]))
            if g.get("nestle-vegan-oat-milk-300ml") == 2:
                issues.append((q, "nestle_oat_as_fresh", "oat"))
        if cls == "health_claim_milk" and g2:
            issues.append((q, "health_claim_has_grade2", str(g2)))
        if rec["amb"] == "" and not g2 and cls not in {
            "none_in_catalog", "health_claim_milk", "probiotic_absent", "flavor_absent",
            "vanilla_absent", "chocolate_milk_brand_absent", "infant_or_kids_absent",
            "uht_kids", "bottle_unverified", "carton_unverified", "powder_tin_unverified",
            "size_300g_absent", "size_680g_absent", "size_4_8kg_absent", "thick_creamer",
            "coffee_mix_absent", "low_sugar_unspecified",
        }:
            issues.append((q, "missing_grade2_unflagged", cls))
    return issues


def main() -> None:
    products, cat = load_catalog()
    G = groups(cat)
    with (EXPERIMENT / "splits.csv").open(encoding="utf-8-sig", newline="") as fh:
        train = [r for r in csv.DictReader(fh) if r["split"] == "train"]
    assert len(train) == 171

    labeled = []
    unmapped = []
    for i, row in enumerate(train, 1):
        grades, cls, amb = label_one(row["query"], cat, G)
        if cls == "UNMAPPED":
            unmapped.append(row["query"])
        labeled.append({
            "train_index": i,
            "query": row["query"],
            "cluster_id": row["cluster_id"],
            "split": "train",
            "intent": row.get("intent") or "",
            "lang": row.get("lang") or "",
            "class": cls,
            "amb": amb,
            "grades": grades,
        })
    if unmapped:
        raise SystemExit("unmapped: " + " | ".join(unmapped))

    issues = checks(labeled, cat, G)

    keys = [p["key"] for p in products]
    wide_path = OUT / "proposed_train_labels_product_first_wide.csv"
    prev = {}
    if wide_path.exists():
        with wide_path.open(encoding="utf-8-sig", newline="") as fh:
            for row in csv.DictReader(fh):
                prev[row["query"]] = {k: int(row[k]) for k in keys if row.get(k) not in (None, "")}
    with wide_path.open("w", encoding="utf-8", newline="") as fh:
        fields = [
            "train_index", "product_class", "ambiguous", "query",
            "cluster_id", "split", "intent", "lang", *keys,
        ]
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        for rec in labeled:
            out = {
                "train_index": rec["train_index"],
                "product_class": rec["class"],
                "ambiguous": rec["amb"],
                "query": rec["query"],
                "cluster_id": rec["cluster_id"],
                "split": "train",
                "intent": rec["intent"],
                "lang": rec["lang"],
            }
            for k in keys:
                out[k] = rec["grades"][k]
            w.writerow(out)

    long_path = OUT / "proposed_train_labels_product_first.csv"
    with long_path.open("w", encoding="utf-8", newline="") as fh:
        fields = [
            "train_index", "product_class", "ambiguous", "query",
            "cluster_id", "split", "intent", "lang",
            "product_key", "product_name", "product_description", "product_category",
            "is_milk", "source", "form", "fat", "sugar", "organic", "volume_ml", "brand",
            "grade",
        ]
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        for rec in labeled:
            for p in products:
                k = p["key"]
                specs = p.get("specs") or {}
                none = p.get("specs") is None
                w.writerow({
                    "train_index": rec["train_index"],
                    "product_class": rec["class"],
                    "ambiguous": rec["amb"],
                    "query": rec["query"],
                    "cluster_id": rec["cluster_id"],
                    "split": "train",
                    "intent": rec["intent"],
                    "lang": rec["lang"],
                    "product_key": k,
                    "product_name": p.get("name") or "",
                    "product_description": p.get("description") or "",
                    "product_category": p.get("category") or "",
                    "is_milk": "" if none else specs.get("is_milk"),
                    "source": specs.get("source") or "",
                    "form": specs.get("form") or "",
                    "fat": specs.get("fat") or "",
                    "sugar": specs.get("sugar") or "",
                    "organic": "" if none else specs.get("organic"),
                    "volume_ml": specs.get("volume_ml") or "",
                    "brand": specs.get("brand") or "",
                    "grade": rec["grades"][k],
                })

    amb_path = OUT / "proposed_train_ambiguous.csv"
    with amb_path.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["train_index", "query", "product_class", "why_human_review", "n_grade2"])
        w.writeheader()
        n_amb = 0
        for rec in labeled:
            if rec["amb"]:
                n_amb += 1
                w.writerow({
                    "train_index": rec["train_index"],
                    "query": rec["query"],
                    "product_class": rec["class"],
                    "why_human_review": rec["amb"],
                    "n_grade2": sum(1 for v in rec["grades"].values() if v == 2),
                })

    dist = Counter()
    no2 = 0
    no2_rows = []
    cell_changes = []
    for rec in labeled:
        vals = list(rec["grades"].values())
        dist.update(vals)
        if 2 not in vals:
            no2 += 1
            no2_rows.append((rec["train_index"], rec["class"], rec["query"]))
        old = prev.get(rec["query"])
        if not old:
            continue
        for k in keys:
            a, b = old.get(k), rec["grades"][k]
            if a is not None and a != b:
                cell_changes.append((rec["train_index"], rec["query"], k, a, b))

    changes_path = OUT / "proposed_train_revision_changes.csv"
    with changes_path.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["train_index", "query", "product_key", "old_grade", "new_grade"])
        w.writeheader()
        for idx, q, k, a, b in cell_changes:
            w.writerow({
                "train_index": idx, "query": q, "product_key": k,
                "old_grade": a, "new_grade": b,
            })

    # official blank untouched
    with (OUT / "labeling_sheet_train_wide.csv").open(encoding="utf-8-sig", newline="") as fh:
        blank = list(csv.DictReader(fh))
    filled = 0
    prod_cols = [p["key"] for p in products]
    for row in blank:
        for k in prod_cols:
            if str(row.get(k) or "").strip():
                filled += 1

    q_changed = sorted({q for _, q, _, _, _ in cell_changes})
    no2_cat_map = {
        "none_in_catalog": "genuine_no_match",
        "health_claim_milk": "health_claim_e1",
        "infant_or_kids_absent": "kids_infant_unsupported",
        "uht_kids": "kids_infant_unsupported",
        "probiotic_absent": "missing_brand_flavor_product",
        "flavor_absent": "missing_brand_flavor_product",
        "vanilla_absent": "missing_brand_flavor_product",
        "chocolate_milk_brand_absent": "missing_brand_flavor_product",
        "powder_tin_unverified": "pack_size_unverified",
        "bottle_unverified": "pack_size_unverified",
        "carton_unverified": "pack_size_unverified",
        "thick_creamer": "pack_size_unverified",
        "size_300g_absent": "pack_size_unverified",
        "size_680g_absent": "pack_size_unverified",
        "size_4_8kg_absent": "pack_size_unverified",
        "coffee_mix_absent": "coffee_mix_absent",
        "low_sugar_unspecified": "low_sugar_unspecified",
    }
    no2_by_category = Counter()
    uncategorized = []
    for _, cls, q in no2_rows:
        catn = no2_cat_map.get(cls)
        if not catn:
            uncategorized.append((cls, q))
        else:
            no2_by_category[catn] += 1
    if uncategorized:
        issues.append(("NO2_TAXONOMY", "uncategorized_no_grade2", str(uncategorized)))
    cat_sum = sum(no2_by_category.values())
    if cat_sum != no2:
        issues.append(("NO2_TAXONOMY", "category_sum_mismatch", f"{cat_sum} != {no2}"))

    chk_path = OUT / "proposed_train_consistency_checks.csv"
    with chk_path.open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["query", "check", "detail"])
        w.writeheader()
        for q, c, d in issues:
            w.writerow({"query": q, "check": c, "detail": d})

    summary = {
        "provisional": True,
        "revision": "approved_glucerna_sugar_taste",
        "queries": 171,
        "cells": 171 * 42,
        "grade_dist": dict(dist),
        "queries_with_no_grade2": no2,
        "queries_with_grade2": 171 - no2,
        "no_grade2_by_category": dict(no2_by_category),
        "no_grade2_category_sum": cat_sum,
        "no_grade2": [{"train_index": i, "class": c, "query": q} for i, c, q in no2_rows],
        "ambiguous_queries": n_amb,
        "cells_changed_vs_previous_provisional": len(cell_changes),
        "queries_changed_vs_previous_provisional": len(q_changed),
        "consistency_issues": len(issues),
        "official_blank_filled_cells": filled,
        "unmapped": unmapped,
    }
    (OUT / "proposed_train_build_status.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps(summary, indent=2))
    if issues:
        print("ISSUES")
        for row in issues[:30]:
            print(row)


if __name__ == "__main__":
    main()
