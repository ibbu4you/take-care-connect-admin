import axios from 'axios';

/*
 | Laravel expects this header to treat a request as AJAX — without it a
 | validation failure comes back as a redirect instead of a 422.
 */
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;

declare global {
    interface Window {
        axios: typeof axios;
    }
}

window.axios = axios;
