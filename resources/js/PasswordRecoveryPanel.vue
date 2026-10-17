<script setup>
import { onMounted, ref } from 'vue';
const props = defineProps({ api: Function });
const parameters = new URLSearchParams(location.hash.slice(1));
const token = ref(parameters.get('token') || ''), email = ref(parameters.get('email') || '');
const resetting = ref(!!token.value), password = ref(''), confirmation = ref('');
const ready = ref(false), loading = ref(true), busy = ref(false), error = ref(''), notice = ref(''), completed = ref(false);
history.replaceState(null, '', location.pathname);
onMounted(async () => {
    try { ready.value = (await props.api('auth-options')).password_recovery_enabled; }
    catch { error.value = 'Chưa kiểm tra được dịch vụ khôi phục. Vui lòng thử lại sau.'; }
    finally { loading.value = false; }
});
function requestNewLink() { resetting.value = false; token.value = ''; error.value = ''; notice.value = ''; password.value = ''; confirmation.value = ''; }
async function submit() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const result = await props.api(resetting.value ? 'reset-password' : 'forgot-password', { method: 'POST', body: JSON.stringify(resetting.value ? { email: email.value, token: token.value, password: password.value, password_confirmation: confirmation.value } : { email: email.value }) });
        notice.value = result.message;
        if (result.csrf_token) document.querySelector('meta[name="csrf-token"]').content = result.csrf_token;
        if (resetting.value) { completed.value = true; token.value = ''; }
    } catch (e) { error.value = e.message; }
    finally { busy.value = false; password.value = ''; confirmation.value = ''; }
}
</script>
<template>
    <main class="recovery-layout">
        <a href="/san-pham" class="brand">NAC<span>20</span><small>CLOUD</small></a>
        <section class="panel recovery-card"><p class="eyebrow">TÀI KHOẢN NAC20 · TN20</p><h1>{{ completed ? 'Mật khẩu đã được cập nhật' : resetting ? 'Đặt mật khẩu mới' : 'Khôi phục tài khoản' }}</h1>
            <p class="muted">{{ resetting ? 'Đặt mật khẩu riêng để tiếp tục quản lý dịch vụ của doanh nghiệp.' : 'Nhập email đăng nhập. Liên kết khôi phục có hiệu lực trong 60 phút và chỉ dùng một lần.' }}</p>
            <p v-if="loading" role="status">Đang kiểm tra dịch vụ…</p><p v-else-if="!ready" class="notice">Khôi phục qua email chưa sẵn sàng. Vui lòng liên hệ TN20 để được hỗ trợ.</p>
            <form v-if="ready && !completed" class="commerce-form" @submit.prevent="submit">
                <label for="recovery-email">Email tài khoản<input id="recovery-email" v-model="email" type="email" autocomplete="username" maxlength="190" required></label>
                <template v-if="resetting"><label for="recovery-password">Mật khẩu mới<input id="recovery-password" v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><label for="recovery-confirmation">Nhập lại mật khẩu mới<input id="recovery-confirmation" v-model="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><p class="fine">Tối thiểu 12 ký tự. Các phiên đăng nhập cũ sẽ hết hiệu lực.</p></template>
                <button class="primary" :disabled="busy">{{ busy ? 'Đang xử lý…' : resetting ? 'Lưu mật khẩu mới' : 'Gửi liên kết khôi phục' }}</button>
            </form>
            <p v-if="error" class="error" role="alert">{{error}}</p><p v-if="notice" class="success-notice" role="status">{{notice}}</p>
            <button v-if="resetting && !completed && ready" class="text-button" :disabled="busy" @click="requestNewLink">Liên kết hết hạn? Yêu cầu liên kết mới</button>
            <a class="back-product" href="/#dang-nhap">← Đăng nhập khách hàng</a><a class="back-product" href="/admin">Đăng nhập quản trị TN20 →</a>
        </section><p class="fine">Công Ty TNHH TN20 · 20.com.vn</p>
    </main>
</template>
<style scoped>
.recovery-layout { max-width: 620px; margin: 0 auto; padding: 48px 24px; }
.recovery-layout > .brand { margin-bottom: 32px; color: #102e3d; }
.recovery-card { padding: 32px; margin-top: 28px; }
.recovery-card h1 { font-size: clamp(24px, 4vw, 32px); line-height: 1.25; }
.recovery-card .back-product { display: block; margin-top: 18px; }
.recovery-layout > .fine { margin-top: 24px; text-align: center; }
</style>
