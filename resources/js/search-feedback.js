export function initSearchFeedback() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-search-click]');
        if (! (link instanceof HTMLElement)) {
            return;
        }

        const productId = link.dataset.productId;
        const token = document.querySelector('[data-products-results-meta]')?.dataset.searchSession
            || document.querySelector('input[name="search_session"]')?.value;
        if (! productId || ! token || ! window.axios) {
            return;
        }

        const body = new FormData();
        body.set('product_id', productId);
        body.set('search_session', token);

        window.axios.post('/search-feedback/click', body, {
            headers: { Accept: 'application/json' },
        }).catch(() => {});
    });
}
