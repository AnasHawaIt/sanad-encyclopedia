import { http } from '@/api/http';

const unwrap = (promise) => promise.then((r) => r.data);

export const asnadsApi = {
    list: (params = {}) => unwrap(http.get('/asnads', { params })),
    show: (id) => unwrap(http.get(`/asnads/${id}`)).then((r) => r.data),
    create: (payload) => unwrap(http.post('/asnads', payload)).then((r) => r.data),
    update: (id, payload) => unwrap(http.put(`/asnads/${id}`, payload)).then((r) => r.data),
    addNode: (id, payload) => unwrap(http.post(`/asnads/${id}/nodes`, payload)).then((r) => r.data),
    updateNode: (id, nodeId, payload) => unwrap(http.put(`/asnads/${id}/nodes/${nodeId}`, payload)).then((r) => r.data),
    removeNode: (id, nodeId) => unwrap(http.delete(`/asnads/${id}/nodes/${nodeId}`)).then((r) => r.data),
    reorder: (id, order) => unwrap(http.put(`/asnads/${id}/nodes/order`, { order })).then((r) => r.data),
    move: (id, nodeId, position) => unwrap(http.post(`/asnads/${id}/nodes/${nodeId}/move`, { position })).then((r) => r.data),
    compare: (ids, scope = 'counted') => unwrap(http.get('/asnads/compare', { params: { ids, scope } })).then((r) => r.data),
    tree: (ids, scope = 'counted') => unwrap(http.get('/asnads/tree', { params: { ids, scope } })).then((r) => r.data),
    bookCompare: (bookId, scope = 'counted') => unwrap(http.get(`/books/${bookId}/asnads/compare`, { params: { scope } })).then((r) => r.data),
    bookTree: (bookId, scope = 'counted') => unwrap(http.get(`/books/${bookId}/asnads/tree`, { params: { scope } })).then((r) => r.data),
    suggestSplit: (text) => unwrap(http.post('/asnads/suggest-split', { text })).then((r) => r.data),
};

export const personsApi = {
    search: (q) => unwrap(http.get('/persons', { params: { q, per_page: 8 } })).then((r) => r.data),
};
