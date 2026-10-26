import { http } from '../lib/http';

/** Profile: avatar upload with a preview, and confirms for the plain forms on the page. */
export default function profilePage({ urls, avatar, t }) {
    return {
        avatar,
        preview: null,
        busy: false,
        showToken: true,

        pick() {
            this.$refs.avatarInput.click();
        },

        async upload(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;

            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = URL.createObjectURL(file);

            const form = new FormData();
            form.append('avatar', file);
            const result = await this.$act(() => http.post(urls.avatar, form), { quiet: true });
            URL.revokeObjectURL(this.preview);
            this.preview = null;
            if (result) {
                this.avatar = result.url;
                this.$store.toast.show(t.avatar_saved);
            }
        },

        async removeAvatar() {
            const ok = await this.$store.confirm.ask({ title: t.avatar_delete_title, action: t.delete });
            if (ok && (await this.$act(() => http.delete(urls.avatarDelete), { quiet: true }))) {
                this.avatar = null;
                this.$store.toast.show(t.avatar_deleted);
            }
        },

        /** <form @submit.prevent="confirmSubmit($el, {...})">: a plain form submit after a confirm. */
        async confirmSubmit(form, options) {
            if (await this.$store.confirm.ask(options)) form.submit();
        },
    };
}
