<script setup>
import { onUnmounted, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
const props = defineProps({ api: Function, tenantId: [Number,String], canManage: Boolean, environment: Object });
defineEmits(['support']);
const result = ref(null), loading = ref(false), error = ref(''), copied = ref('');
let epoch = 0;
async function load() {
    const token=++epoch; if (!props.canManage) { result.value=null; return; }
    loading.value=true; error.value='';
    try { const value=await props.api(`tenants/${props.tenantId}/access`); if(token===epoch)result.value=value; }
    catch(e) { if(token===epoch){error.value=e.message;result.value=null;} }
    finally { if(token===epoch)loading.value=false; }
}
async function copy(value) { try { await navigator.clipboard.writeText(value); copied.value='Đã sao chép thông tin.'; } catch { copied.value='Hãy chọn nội dung và sao chép thủ công trên thiết bị này.'; } }
watch(()=>[props.tenantId, props.environment?.updated_at, props.canManage],()=>{copied.value='';load();},{immediate:true});
onUnmounted(()=>{epoch++;});
</script>
<template>
    <section class="panel access-panel" :aria-busy="loading"><div class="section-heading"><div class="heading-with-icon"><span class="feature-icon"><AppIcon name="access" :size="24" /></span><div><p class="eyebrow">KHÔNG GIAN RIÊNG CỦA DOANH NGHIỆP</p><h2>Truy cập NAC20</h2></div></div><button class="secondary" :disabled="loading || !canManage" @click="load"><AppIcon name="refresh" :size="16" />Cập nhật</button></div>
        <p v-if="error" class="error" role="alert">{{error}}</p>
        <p v-if="!canManage" class="notice">Thông tin tài khoản quản trị NAC20 chỉ dành cho chủ công ty và quản trị viên được cấp quyền.</p>
        <div v-else-if="result?.available" class="access-ready"><div class="access-banner"><AppIcon name="security" :size="28" /><div><span class="badge" data-status="ready">Đã bàn giao</span><h3>Môi trường NAC20 của bạn đã sẵn sàng</h3><p>TN20 đã công bố địa chỉ và tài khoản truy cập cho doanh nghiệp.</p></div></div><dl class="environment-facts"><div><dt>Địa chỉ quản trị</dt><dd class="copy-field"><a :href="result.url" target="_blank" rel="noopener noreferrer">{{result.url}}</a><button class="icon-button" aria-label="Sao chép địa chỉ quản trị" @click="copy(result.url)"><AppIcon name="orders" :size="17" /></button></dd></div><div><dt>Tên tài khoản NAC20</dt><dd class="copy-field"><code>{{result.account}}</code><button class="icon-button" aria-label="Sao chép tên tài khoản" @click="copy(result.account)"><AppIcon name="orders" :size="17" /></button></dd></div><div><dt>Ngày bàn giao</dt><dd>{{new Date(result.handed_over_at).toLocaleString('vi-VN')}}</dd></div><div><dt>Mật khẩu đăng nhập</dt><dd>TN20 bàn giao qua kênh riêng đã thống nhất.</dd></div></dl><div v-if="result.instructions" class="access-instructions"><h3>Thông tin bàn giao</h3><p class="preserve-text">{{result.instructions}}</p></div><a class="primary" :href="result.url" target="_blank" rel="noopener noreferrer">Mở trang quản trị NAC20<AppIcon name="access" :size="17" /></a><p class="fine left-aligned">Tài khoản NAC20 được cấp riêng. Hiện chưa có đăng nhập một lần từ cổng dịch vụ.</p></div>
        <div v-else-if="loading && !result" class="loading-panel" role="status"><div class="skeleton-line"></div><p>Đang kiểm tra thông tin bàn giao…</p></div>
        <div v-else class="empty-state"><span class="empty-state-icon"><AppIcon :name="environment?.status === 'awaiting_configuration' ? 'security' : 'infrastructure'" :size="30" /></span><h3>{{environment?.status === 'ready' ? 'Đang hoàn tất thông tin truy cập' : environment?.status === 'awaiting_configuration' ? 'Môi trường đang chờ nghiệm thu' : 'Thông tin truy cập sẽ xuất hiện tại đây'}}</h3><p>{{environment?.status === 'ready' ? 'TN20 sẽ công bố địa chỉ quản trị và tài khoản sau khi xác nhận thông tin bàn giao.' : 'Sau khi cài đặt, cấu hình và nghiệm thu, TN20 sẽ bàn giao môi trường riêng cho doanh nghiệp.'}}</p><button class="secondary" @click="$emit('support')">Trao đổi với TN20<AppIcon name="arrow" :size="16" /></button></div><p v-if="copied" class="success-notice" role="status">{{copied}}</p>
    </section><section class="panel access-guide"><h2>Bắt đầu sử dụng dịch vụ</h2><div class="guide-grid"><article><span>01</span><h3>Nhận thông tin bàn giao</h3><p>Kiểm tra đúng tên miền và tài khoản của công ty. Mật khẩu được gửi qua kênh riêng đã thống nhất.</p></article><article><span>02</span><h3>Đăng nhập môi trường NAC20</h3><p>Mở trang quản trị đã được TN20 công bố và làm theo hướng dẫn đặt mật khẩu khi bàn giao.</p></article><article><span>03</span><h3>Vận hành cùng TN20</h3><p>Theo dõi mạng, quản lý chính sách theo quyền được cấp và gửi yêu cầu hỗ trợ trong cổng dịch vụ.</p></article></div></section>
</template>
