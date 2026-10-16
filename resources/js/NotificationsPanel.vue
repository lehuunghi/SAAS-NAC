<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
const props=defineProps({api:Function});
const result=ref(null),page=ref(1),busy=ref(false),error=ref('');
let epoch=0,timer;
async function load(){const token=++epoch;busy.value=true;try{const value=await props.api('notifications?page='+page.value);if(token===epoch){result.value=value;error.value='';}}catch(e){if(token===epoch)error.value=e.message;}finally{if(token===epoch)busy.value=false;}}
async function read(item){try{await props.api(`notifications/${item.id}/read`,{method:'POST'});await load();}catch(e){error.value=e.message;}}
onMounted(()=>{load();timer=setInterval(()=>{if(!busy.value && document.visibilityState==='visible')load();},10000);});
onUnmounted(()=>{epoch++;clearInterval(timer);});
</script>
<template>
    <section class="panel"><div class="section-heading"><h2>Thông báo triển khai</h2><button class="secondary" :disabled="busy" @click="load">Cập nhật</button></div><p class="muted">Kết quả cài đặt, tác vụ lỗi và tác vụ đã hủy. Thông báo trong cổng, chưa gửi email hoặc SMS.</p><p v-if="error" class="error" role="alert">{{error}}</p><p v-if="result" class="notice">{{result.unread_count}} thông báo chưa đọc</p><template v-if="result"><article v-for="item in result.notifications.data" :key="item.id" class="ticket-item"><div class="section-heading"><h3>{{item.title}}</h3><span class="badge">{{item.read_at?'Đã đọc':'Chưa đọc'}}</span></div><p><b>{{item.tenant?.name}}</b> · Tác vụ #{{item.operation_id}} · Lần {{item.attempt}}</p><p>{{item.message}}</p><p class="fine">{{new Date(item.created_at).toLocaleString('vi-VN')}}</p><button v-if="!item.read_at" class="secondary" @click="read(item)">Đánh dấu đã đọc</button></article><p v-if="!result.notifications.data.length" class="empty">Chưa có thông báo.</p><div class="pagination"><button :disabled="busy || page<=1" @click="page--;load()">←</button><span>Trang {{page}}</span><button :disabled="busy || page>=result.notifications.last_page" @click="page++;load()">→</button></div></template>
    </section>
</template>
