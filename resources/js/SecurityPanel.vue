<script setup>
import { ref } from 'vue';
const props = defineProps({ api: Function });
const currentPassword = ref(''), password = ref(''), confirmation = ref('');
const busy = ref(false), error = ref(''), notice = ref('');
async function changePassword() {
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const result = await props.api('password', { method: 'POST', body: JSON.stringify({ current_password: currentPassword.value, password: password.value, password_confirmation: confirmation.value }) });
        document.querySelector('meta[name="csrf-token"]').content = result.csrf_token;
        notice.value = result.message;
    } catch (e) { error.value = e.message; }
    finally { busy.value = false; currentPassword.value = ''; password.value = ''; confirmation.value = ''; }
}
</script>
<template>
    <section class="panel"><p class="eyebrow">BẢO MẬT TÀI KHOẢN</p><h2>Đổi mật khẩu</h2><p class="muted">Dùng mật khẩu riêng, tối thiểu 12 ký tự. Sau khi đổi, các phiên khác sẽ bị đăng xuất ở lần truy cập tiếp theo.</p>
        <form class="commerce-form" @submit.prevent="changePassword"><label>Mật khẩu hiện tại<input v-model="currentPassword" type="password" autocomplete="current-password" required></label><label>Mật khẩu mới<input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><label>Nhập lại mật khẩu mới<input v-model="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label><p v-if="error" class="error" role="alert">{{error}}</p><p v-if="notice" class="success-notice" role="status">{{notice}}</p><button class="primary" :disabled="busy">{{busy ? 'Đang đổi mật khẩu…' : 'Lưu mật khẩu mới'}}</button></form>
    </section>
</template>
