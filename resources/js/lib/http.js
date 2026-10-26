/**
 * fetch() with the panel's conventions: JSON in and out, the CSRF token from the
 * meta tag, and one error shape: { status, message, errors } thrown as HttpError.
 *
 * A FormData body goes as multipart: the browser sets the Content-Type (with
 * the boundary) itself, so it is never forced to JSON here.
 */
export class HttpError extends Error {
    constructor(status, message, errors = {}, body = null) {
        super(message);
        this.status = status;
        this.errors = errors;
        this.body = body;
    }

    /** First validation message of a field, or null. */
    field(name) {
        return this.errors?.[name]?.[0] ?? null;
    }
}

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const baseHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': csrf(),
});

/** The one place a response becomes a result or an HttpError. */
function settle(status, body) {
    if (status === 419) {
        window.location.reload();
    }
    if (status < 200 || status >= 300) {
        const message = body?.message || window.__t?.request_failed || `HTTP ${status}`;
        throw new HttpError(status, message, body?.errors ?? {}, body);
    }

    return body;
}

export async function request(method, url, data = null, { signal } = {}) {
    const options = { method, signal, credentials: 'same-origin', headers: baseHeaders() };

    if (data instanceof FormData) {
        options.body = data;
    } else if (data !== null && method !== 'GET') {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    } else if (data !== null && method === 'GET') {
        url = withQuery(url, data);
    }

    let response;
    try {
        response = await fetch(url, options);
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        throw new HttpError(0, window.__t?.network_error ?? 'Network error');
    }

    const body = (response.headers.get('content-type') ?? '').includes('application/json') ? await response.json() : null;

    return settle(response.status, body);
}

/**
 * POST a FormData with upload progress (fetch can't report it): onProgress gets
 * 0..100. Same result and error shape as request().
 */
export function upload(url, form, { onProgress, signal } = {}) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.withCredentials = true;
        for (const [name, value] of Object.entries(baseHeaders())) xhr.setRequestHeader(name, value);

        if (onProgress) {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) onProgress(Math.min(100, Math.round((e.loaded / e.total) * 100)));
            });
        }
        signal?.addEventListener('abort', () => xhr.abort());

        xhr.addEventListener('load', () => {
            let body = null;
            if ((xhr.getResponseHeader('content-type') ?? '').includes('application/json')) {
                try {
                    body = JSON.parse(xhr.responseText);
                } catch {
                    body = null;
                }
            }
            try {
                resolve(settle(xhr.status, body));
            } catch (e) {
                reject(e);
            }
        });
        xhr.addEventListener('error', () => reject(new HttpError(0, window.__t?.network_error ?? 'Network error')));
        xhr.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));

        xhr.send(form);
    });
}

export const http = {
    get: (url, query = null, opts) => request('GET', url, query, opts),
    post: (url, data = {}, opts) => request('POST', url, data, opts),
    put: (url, data = {}, opts) => request('PUT', url, data, opts),
    delete: (url, data = null, opts) => request('DELETE', url, data, opts),
    upload,
};

/** url + ?a=1&b[]=2, skipping empty values. */
export function withQuery(url, query) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query ?? {})) {
        if (value === null || value === undefined || value === '') continue;
        if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, v));
        else params.append(key, value);
    }
    const qs = params.toString();
    if (!qs) return url;

    return url + (url.includes('?') ? '&' : '?') + qs;
}
