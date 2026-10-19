/** يحوّل خطأ Laravel (422 وغيره) إلى رسالة عربية واحدة جاهزة للعرض. */
export function errorMessage(error, fallback = 'تعذّر تنفيذ العملية. حاول مرة أخرى.') {
    const response = error?.response;
    if (!response) {
        return fallback;
    }
    if (response.status === 403) {
        return 'لا تملك صلاحية تنفيذ هذه العملية.';
    }
    if (response.status === 404) {
        return 'السجل غير موجود أو لا يمكنك الاطلاع عليه.';
    }
    const errors = response.data?.errors;
    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return String(first);
        }
    }
    return response.data?.message || fallback;
}
