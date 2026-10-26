import { http } from '@/api/http';

/** اسم الملف من ترويسة Content-Disposition، وإلا اسم افتراضي */
export function filenameOf(disposition, fallback) {
    const match = /filename="?([^";]+)"?/i.exec(disposition || '');
    return match ? match[1] : fallback;
}

/** يحوّل جسم خطأ وصل كملف (blob) إلى كائن الخطأ المعتاد لتعمل errorMessage عليه */
async function unblob(error) {
    const data = error?.response?.data;
    if (data instanceof Blob) {
        try {
            error.response.data = JSON.parse(await data.text());
        } catch {
            error.response.data = {};
        }
    }
    return error;
}

export const exportsApi = {
    /** ينزّل ملف التصدير عبر المتصفح ويعيد {count,total,truncated} */
    async download(kind, params = {}) {
        let response;
        try {
            response = await http.get(`/exports/${kind}`, { params, responseType: 'blob', headers: { Accept: '*/*' } });
        } catch (e) {
            throw await unblob(e);
        }
        const name = filenameOf(response.headers['content-disposition'], `sanad-${kind}`);
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);

        return {
            count: Number(response.headers['x-export-count'] ?? 0),
            total: Number(response.headers['x-export-total'] ?? 0),
            truncated: response.headers['x-export-truncated'] === '1',
        };
    },
};
