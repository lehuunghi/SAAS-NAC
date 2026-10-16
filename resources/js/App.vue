<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PublicSite from './PublicSite.vue';
import CommercePanel from './CommercePanel.vue';
import AdminPanel from './AdminPanel.vue';
import AccountPanel from './AccountPanel.vue';

const user = ref(null), companies = ref([]), selected = ref(''), data = ref(null);
const page = ref('overview'), error = ref(''), busy = ref(false), loading = ref(true);
const email = ref(''), password = ref(''), siteName = ref(''), siteCode = ref('');
const adminPage = ref(location.pathname === '/admin');
const authView = ref(location.hash === '#dang-nhap' || adminPage.value), registerMode = ref(false), registrationEnabled = ref(false);
const fullName = ref(''), companyName = ref(''), confirmPassword = ref('');
const publicPage = ref(location.pathname === '/san-pham');
let timer, generation = 0;
const labels = { requested: 'Đã yêu cầu', queued: 'Đang chờ', running: 'Đang thực hiện', provisioning: 'Đang cấp phát', simulated: 'Đã mô phỏng', completed: 'Hoàn tất tác vụ', failed: 'Cần xử lý', awaiting_configuration: 'Chờ cấu hình NAC20', ready: 'Đã được TN20 nghiệm thu / bàn giao' };
const stateLabel = value => labels[value] || value;
const env = computed(() => data.value?.environments?.[0]);
const navigation = [ ['overview', '◈', 'Tổng quan'], ['quotes', '◇', 'Tư vấn & báo giá'], ['orders', '▧', 'Đơn hàng'], ['payments', '◉', 'Lịch sử thanh toán'], ['subscription', '▣', 'Dịch vụ của tôi'], ['sites', '▦', 'Chi nhánh'], ['infrastructure', '▤', 'Hạ tầng NAC20'], ['company', '▢', 'Hồ sơ công ty'], ['team', '♙', 'Thành viên'], ['support', '◌', 'Hỗ trợ'], ['activity', '◷', 'Lịch sử hoạt động'] ];
async function api(path, options = {}) {
    const response = await fetch('/saas/api/v1/' + path, { credentials: 'same-origin', ...options,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, ...options.headers } });
    const body = response.status === 204 ? null : await response.json();
    if (!response.ok) {
        if (response.status === 401) { user.value = null; data.value = null; }
        throw new Error(Object.values(body?.errors || {}).flat()[0] || body?.message || 'Yêu cầu không thành công.');
    }
    return body;
}
async function selectCompany(id) {
    generation++; selected.value = id; data.value = null; error.value = ''; siteName.value = ''; siteCode.value = ''; page.value = 'overview'; await refresh();
}
async function refresh() {
    if (!selected.value) return;
    const token = generation, id = selected.value;
    try { const next = await api('tenants/' + id); if (token === generation && id === selected.value) data.value = next; }
    catch (e) { if (token === generation) error.value = e.message; }
}
async function login() {
    busy.value = true; error.value = '';
    try {
        const body = registerMode.value ? { name: fullName.value, company: companyName.value, email: email.value, password: password.value, password_confirmation: confirmPassword.value } : { email: email.value, password: password.value };
        await api(registerMode.value ? 'register' : 'login', { method: 'POST', body: JSON.stringify(body) });
        if (adminPage.value) { location.reload(); } else if (location.pathname === '/') { location.hash = '#devis'; location.reload(); } else { location.href = '/#devis'; }
    }
    catch (e) { error.value = e.message; } finally { password.value = ''; busy.value = false; }
}
async function logout() { try { await api('logout', { method: 'POST' }); location.reload(); } catch (e) { error.value = e.message; } }
async function createSite() {
    busy.value = true; error.value = '';
    try { await api(`tenants/${selected.value}/sites`, { method: 'POST', body: JSON.stringify({ name: siteName.value, code: siteCode.value }) }); siteName.value = ''; siteCode.value = ''; await refresh(); }
    catch (e) { error.value = e.message; } finally { busy.value = false; }
}
async function provision() {
    busy.value = true; error.value = '';
    try { await api(`tenants/${selected.value}/environments`, { method: 'POST', body: JSON.stringify({ idempotency_key: crypto.randomUUID() }) }); await refresh(); }
    catch (e) { error.value = e.message; } finally { busy.value = false; }
}
onMounted(async () => {
    try { const catalog = await api('catalog'); registrationEnabled.value = catalog.registration_enabled; user.value = await api('me'); companies.value = await api('tenants'); if (companies.value.length) await selectCompany(companies.value[0].id); if (location.hash.startsWith('#devis')) page.value = 'quotes'; }
    catch (e) { if (user.value) error.value = e.message; } finally { loading.value = false; }
    timer = setInterval(() => { if (user.value && !busy.value) refresh(); }, 6000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <div v-if="loading" class="boot" role="status">Đang mở không gian NAC20…</div>
    <template v-else-if="adminPage && user"><AdminPanel v-if="user.is_platform_admin" :user="user" :api="api" @logout="logout"/><main v-else class="content"><section class="panel"><h1>Khu quản trị TN20</h1><p class="notice">Tài khoản hiện tại không có quyền quản trị nền tảng.</p><a class="primary" href="/">Về khu khách hàng</a><button class="text-button" @click="logout">Đăng xuất</button></section></main></template>
    <PublicSite v-else-if="publicPage || (!user && !authView)" :authenticated="!!user" @login="publicPage=false; authView=true; registerMode=false" @register="publicPage=false; authView=true; registerMode=true" />
    <main v-else-if="!user" class="login-layout">
        <section class="login-story"><a class="brand" href="/">NAC<span>20</span><small>CLOUD</small></a><div><p class="eyebrow">HẠ TẦNG TẬP TRUNG · KIỂM SOÁT ĐỘC LẬP</p><h1>Mọi chi nhánh.<br>Một không gian<br><em>điều hành.</em></h1><p>Quản lý môi trường NAC20 của doanh nghiệp trên nền tảng dịch vụ của TN20.</p><div class="story-grid"><span>Môi trường riêng</span><span>Quyền theo công ty</span><span>Kết nối Connector</span></div></div><footer>Công Ty TNHH TN20 · 20.com.vn</footer></section>
        <section class="login-panel"><form @submit.prevent="login"><p class="eyebrow">NAC20 CLOUD · TN20</p><h2>{{ registerMode ? 'Tạo tài khoản tư vấn' : 'Đăng nhập NAC20' }}</h2><p class="muted">{{ registerMode ? 'Tạo không gian công ty để gửi nhu cầu và nhận báo giá riêng.' : 'Đăng nhập để theo dõi dịch vụ, đơn hàng và triển khai.' }}</p><template v-if="registerMode"><label for="full-name">Họ tên</label><input id="full-name" v-model="fullName" maxlength="100" autocomplete="name" required><label for="company-name">Tên công ty</label><input id="company-name" v-model="companyName" maxlength="150" autocomplete="organization" required></template><label for="email">Địa chỉ email</label><input id="email" v-model="email" type="email" autocomplete="username" required placeholder="ban@congty.vn"><label for="password">Mật khẩu</label><input id="password" v-model="password" type="password" :autocomplete="registerMode ? 'new-password' : 'current-password'" :minlength="registerMode ? 12 : undefined" required><template v-if="registerMode"><label for="confirm-password">Nhập lại mật khẩu</label><input id="confirm-password" v-model="confirmPassword" type="password" autocomplete="new-password" required></template><p v-if="error" class="error" role="alert">{{ error }}</p><button class="primary" :disabled="busy">{{ busy ? 'Đang xử lý…' : registerMode ? 'Tạo tài khoản →' : 'Vào không gian quản trị →' }}</button><button v-if="registrationEnabled" type="button" class="text-button" @click="registerMode=!registerMode; error=''">{{ registerMode ? 'Đã có tài khoản? Đăng nhập' : 'Chưa có tài khoản? Đăng ký tư vấn' }}</button><a class="back-product" href="/san-pham">← Giới thiệu sản phẩm</a><p class="fine">Tạo tài khoản chưa thu phí hoặc tự cấp máy chủ.</p></form></section>
    </main>
    <div v-else class="workspace">
        <aside class="sidebar"><a href="/" class="brand">NAC<span>20</span><small>CLOUD</small></a><div class="company-picker"><label for="company">KHÔNG GIAN CÔNG TY</label><select id="company" :value="selected" @change="selectCompany(Number($event.target.value))"><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></div><nav aria-label="Điều hướng chính"><button v-for="item in navigation" :key="item[0]" :class="{ active: page === item[0] }" @click="page = item[0]"><span>{{ item[1] }}</span>{{ item[2] }}</button></nav><div class="sidebar-bottom"><span class="dot"></span> Công Ty TNHH TN20<p>20.com.vn</p></div></aside>
        <div class="main-area"><header class="topbar"><span>Nền tảng dịch vụ <b>/ {{ navigation.find(n => n[0] === page)?.[2] }}</b></span><div><span class="avatar">{{ user.name.slice(0,1) }}</span><span>{{ user.name }}</span><button class="text-button" @click="logout">Đăng xuất</button></div></header>
            <main class="content"><p v-if="user.is_platform_admin" class="notice"><a href="/admin">↗ Chuyển sang quản trị nền tảng TN20</a></p><div class="page-heading"><div><p class="eyebrow">NAC20 · {{ data?.tenant?.slug || 'KHÔNG GIAN DOANH NGHIỆP' }}</p><h1>{{ navigation.find(n => n[0] === page)?.[2] }}</h1><p class="muted">{{ data?.tenant?.name || 'Chọn công ty để bắt đầu.' }}</p></div><button class="secondary" @click="refresh">↻ Cập nhật</button></div><p v-if="error" role="alert" class="error">{{ error }}</p><p v-if="!companies.length" class="panel">Tài khoản chưa được gán công ty. Liên hệ quản trị TN20 để cấp quyền.</p>
                <template v-if="data">
                    <CommercePanel v-if="['quotes','orders','payments','subscription','support'].includes(page)" :tenant-id="selected" :page="page" :can-manage="data.can_manage" :api="api" :key="selected" />
                    <AccountPanel v-if="['company','team'].includes(page)" :key="selected" :tenant="data.tenant" :user="user" :can-manage="data.can_manage" :page="page" :api="api" />
                    <template v-if="page === 'overview'">
                        <section class="hero"><div><p class="eyebrow">KHÔNG GIAN CỦA DOANH NGHIỆP</p><h2>Hạ tầng riêng.<br>Điều hành tập trung.</h2><p>Theo dõi chi nhánh, môi trường NAC20 và tiến trình triển khai của công ty.</p><button class="light-button" @click="page = 'infrastructure'">Xem hạ tầng NAC20 ↗</button></div><div class="network-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="network-core">NAC<b>20</b></div><span class="node node-a"></span><span class="node node-b"></span><span class="node node-c"></span></div></section>
                        <section class="stats"><article><span>Chi nhánh</span><strong>{{ data.sites.length }}</strong><small>Trong công ty đang chọn</small></article><article><span>Môi trường NAC20</span><strong>{{ data.environments.length }}</strong><small>Mỗi công ty một môi trường riêng</small></article><article><span>Trạng thái triển khai</span><strong class="stat-text">{{ env ? stateLabel(env.status) : 'Chưa khởi tạo' }}</strong><small>Theo kết quả tác vụ máy chủ</small></article></section>
                    </template>
                    <section v-if="page === 'sites'" class="panel"><div class="section-heading"><h2>Chi nhánh doanh nghiệp</h2><span>{{ data.sites.length }} chi nhánh</span></div><form v-if="data.can_manage" class="inline-form" @submit.prevent="createSite"><label>Tên chi nhánh<input v-model="siteName" required maxlength="120" placeholder="Văn phòng Hà Nội"></label><label>Mã chi nhánh<input v-model="siteCode" required pattern="[a-z0-9-]{2,50}" placeholder="ha-noi"></label><button class="primary" :disabled="busy">Thêm chi nhánh</button></form><table v-if="data.sites.length"><thead><tr><th>Tên chi nhánh</th><th>Mã</th></tr></thead><tbody><tr v-for="site in data.sites" :key="site.id"><td>{{ site.name }}</td><td><code>{{ site.code }}</code></td></tr></tbody></table><p v-else class="empty">Chưa có chi nhánh. Quản trị viên có thể thêm chi nhánh tại đây.</p></section>
                    <section v-if="page === 'infrastructure'" class="panel"><div class="section-heading"><h2>Môi trường NAC20</h2><span class="badge">Cách ly theo công ty</span></div><template v-if="env"><dl class="environment-facts"><div><dt>Tên miền dự kiến</dt><dd>{{ env.hostname }}</dd></div><div><dt>Trạng thái</dt><dd>{{ stateLabel(env.status) }}</dd></div><div><dt>Phương thức</dt><dd>{{ env.driver === 'simulation' ? 'Mô phỏng — không tạo máy chủ' : 'Hetzner + Cloudflare + Ansible' }}</dd></div><div><dt>IP máy chủ</dt><dd>{{ env.ip_address || 'Chưa cấp' }}</dd></div></dl><p v-if="env.status === 'simulated'" class="notice">Tác vụ này chỉ chạy thử quy trình. Chưa tạo máy chủ, DNS hoặc dịch vụ NAC20 thật.</p><p v-if="env.status === 'awaiting_configuration'" class="notice">Đã cài gói. TN20 cần hoàn tất trình cấu hình, TLS và nghiệm thu mạng trước khi bàn giao.</p></template><template v-else><p class="empty">Công ty chưa có môi trường NAC20.</p><p class="muted">Yêu cầu sử dụng phương thức do TN20 cấu hình. Mặc định là mô phỏng; cấp phát thật chỉ bật khi đã chuẩn bị hạ tầng.</p><button v-if="data.can_manage" class="primary" :disabled="busy" @click="provision">{{ busy ? 'Đang gửi…' : 'Yêu cầu khởi tạo môi trường' }}</button></template></section>
                    <section v-if="page === 'overview' || page === 'infrastructure'" class="panel"><div class="section-heading"><h2>Tiến trình triển khai</h2><span>20 tác vụ gần nhất</span></div><div class="table-scroll"><table v-if="data.operations.length"><thead><tr><th>Mã tác vụ</th><th>Trạng thái</th><th>Bước thực hiện</th><th>Cập nhật</th></tr></thead><tbody><tr v-for="op in data.operations" :key="op.id"><td>#{{ op.id }}</td><td><span class="badge" :class="{ danger: op.status === 'failed' }">{{ stateLabel(op.status) }}</span></td><td><code>{{ op.stage }}</code><small v-if="op.error_code" class="error-code">{{ op.error_code }}</small></td><td>{{ new Date(op.updated_at).toLocaleString('vi-VN') }}</td></tr></tbody></table><p v-else class="empty">Chưa có yêu cầu triển khai.</p></div></section>
                    <section v-if="page === 'activity'" class="panel"><div class="section-heading"><h2>Nhật ký quản trị</h2><span>20 sự kiện gần nhất</span></div><table v-if="data.audit.length"><thead><tr><th>Hành động</th><th>Đối tượng</th><th>Thời gian</th></tr></thead><tbody><tr v-for="event in data.audit" :key="event.id"><td>{{ event.action }}</td><td>#{{ event.subject }}</td><td>{{ new Date(event.created_at).toLocaleString('vi-VN') }}</td></tr></tbody></table><p v-else class="empty">Chưa có hoạt động được ghi nhận.</p></section>
                    <footer class="content-footer"><a href="/san-pham">NAC20 Cloud · Giới thiệu sản phẩm ↗</a><span>Không gian riêng cho {{ data.tenant.name }}</span></footer>
                </template>
            </main>
        </div>
    </div>
</template>
