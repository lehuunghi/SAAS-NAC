<script setup>
defineProps({ name: String, size: { type: Number, default: 20 } });
const paths = {
    overview: ['M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
    customers: ['M4 21V7l8-4 8 4v14M9 21v-5h6v5M8 8h1m6 0h1M8 12h1m6 0h1M2 21h20'],
    company: ['M4 21V7l8-4 8 4v14M9 21v-5h6v5M8 8h1m6 0h1M8 12h1m6 0h1M2 21h20'],
    quotes: ['M7 3h10l3 3v15H4V3h3M8 9h8M8 13h8M8 17h4'],
    sales: ['M7 3h10l3 3v15H4V3h3M8 9h8M8 13h8M8 17h4'],
    orders: ['M5 4h14v17l-3-2-4 2-4-2-3 2V4zM9 8h6M9 12h6'],
    payments: ['M3 6h18v13H3zM3 10h18M7 15h3'],
    subscription: ['M12 3l8 4v6c0 4-8 8-8 8s-8-4-8-8V7l8-4zM8 12l3 3 5-6'],
    sites: ['M4 3h6v6H4zM14 15h6v6h-6zM4 15h6v6H4zM7 9v3h10v3M7 12v3'],
    infrastructure: ['M4 3h16v7H4zM4 14h16v7H4zM7 6h.01M7 17h.01M12 6h5M12 17h5'],
    installations: ['M4 3h16v7H4zM4 14h16v7H4zM7 6h.01M7 17h.01M12 6h5M12 17h5'],
    access: ['M13 4h7v7M20 4l-9 9M10 4H4v16h16v-6'],
    team: ['M16 21v-3a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v3M10 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8M17 4a4 4 0 0 1 0 7M20 21v-3a4 4 0 0 0-3-4'],
    users: ['M16 21v-3a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v3M10 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8M17 4a4 4 0 0 1 0 7M20 21v-3a4 4 0 0 0-3-4'],
    security: ['M12 3l8 4v6c0 4-8 8-8 8s-8-4-8-8V7l8-4zM8 12l3 3 5-6'],
    notifications: ['M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4'],
    support: ['M4 13v-2a8 8 0 0 1 16 0v2M4 12H2v6h4v-6H4M20 12h2v6h-4v-6h2M20 18v3h-7'],
    tickets: ['M4 13v-2a8 8 0 0 1 16 0v2M4 12H2v6h4v-6H4M20 12h2v6h-4v-6h2M20 18v3h-7'],
    activity: ['M3 12a9 9 0 1 0 3-6M3 3v6h6M12 7v5l3 2'],
    audit: ['M3 12a9 9 0 1 0 3-6M3 3v6h6M12 7v5l3 2'],
    settings: ['M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2'],
    menu: ['M4 6h16M4 12h16M4 18h16'], close: ['M6 6l12 12M6 18L18 6'],
    arrow: ['M4 12h16M14 6l6 6-6 6'], check: ['M5 12l4 4L19 6'],
    refresh: ['M20 7v5h-5M4 17v-5h5M5 8a7 7 0 0 1 12-3l3 4M4 15l3 4a7 7 0 0 0 12-3'],
};
</script>
<template><svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="app-icon"><path v-for="(path,index) in paths[name] || paths.overview" :key="index" :d="path" /></svg></template>
