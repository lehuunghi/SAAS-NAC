<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import AppIcon from './AppIcon.vue';
const open = ref(false), trigger = ref(null);
function syncSidebar() { const sidebar=document.getElementById('workspace-navigation'); if(!sidebar)return; const mobile=window.innerWidth<=1024; sidebar.inert=mobile&&!open.value; if(mobile&&open.value){sidebar.setAttribute('role','dialog');sidebar.setAttribute('aria-modal','true');}else{sidebar.removeAttribute('role');sidebar.removeAttribute('aria-modal');} }
function close(restore = false) { open.value = false; document.body.classList.remove('navigation-open'); syncSidebar(); if (restore) trigger.value?.focus(); }
function toggle() { if (open.value) return close(true); open.value = true; document.body.classList.add('navigation-open'); syncSidebar(); requestAnimationFrame(() => document.querySelector('#workspace-navigation a')?.focus()); }
function handleClick(event) { if (event.target.closest('#workspace-navigation nav button, #workspace-navigation a')) { close(); document.getElementById('workspace-main')?.focus({preventScroll:true}); } }
function handleKey(event) {
    if (!open.value) return;
    if (event.key === 'Escape') { event.preventDefault(); close(true); }
    if (event.key !== 'Tab') return;
    const controls = [trigger.value, ...document.querySelectorAll('#workspace-navigation a, #workspace-navigation button, #workspace-navigation select')].filter(el => el && !el.disabled && el.getClientRects().length);
    const first = controls[0], last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
}
function handleResize() { if (window.innerWidth > 1024) close(); else syncSidebar(); }
onMounted(() => { syncSidebar(); document.addEventListener('click',handleClick); document.addEventListener('keydown',handleKey); window.addEventListener('resize',handleResize); });
onUnmounted(() => { close(); document.removeEventListener('click',handleClick); document.removeEventListener('keydown',handleKey); window.removeEventListener('resize',handleResize); });
</script>
<template><button ref="trigger" type="button" class="workspace-menu" :aria-label="open ? 'Đóng điều hướng' : 'Mở điều hướng'" :aria-expanded="open" aria-controls="workspace-navigation" @click="toggle"><AppIcon :name="open ? 'close' : 'menu'" /></button><button v-if="open" type="button" class="navigation-scrim" aria-label="Đóng điều hướng" tabindex="-1" @click="close(true)"></button></template>
