import { http } from '@/api/http';

const unwrap = (promise) => promise.then((r) => r.data);

export const reviewsApi = {
    queue: (type) => unwrap(http.get('/reviews/queue', { params: { type: type || undefined } })).then((r) => r.data),
    history: (type, id) => unwrap(http.get(`/${type}/${id}/reviews`)).then((r) => r.data),
    act: (type, id, action, notes) => unwrap(http.post(`/${type}/${id}/${action}`, notes ? { notes } : {})).then((r) => r.data),
};

export const mergesApi = {
    list: (params = {}) => unwrap(http.get('/merge-requests', { params })),
    show: (id) => unwrap(http.get(`/merge-requests/${id}`)).then((r) => r.data),
    create: (payload) => unwrap(http.post('/merge-requests', payload)).then((r) => r.data),
    approve: (id, notes) => unwrap(http.post(`/merge-requests/${id}/approve`, notes ? { notes } : {})).then((r) => r.data),
    reject: (id, notes) => unwrap(http.post(`/merge-requests/${id}/reject`, { notes })).then((r) => r.data),
    withdraw: (id) => unwrap(http.delete(`/merge-requests/${id}`)).then((r) => r.data),
    revert: (id, notes) => unwrap(http.post(`/merge-requests/${id}/revert`, { notes })).then((r) => r.data),
};

export const auditApi = {
    list: (params = {}) => unwrap(http.get('/audit', { params })),
    events: () => unwrap(http.get('/audit/events')).then((r) => r.data),
};
