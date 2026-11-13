import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { app } from './lib/api.js'
import './app.css'

const routes = [
  { path: '/', name: 'overview', component: () => import('./pages/Overview.vue') },
  { path: '/analytics', name: 'analytics', component: () => import('./pages/Analytics.vue') },
  { path: '/targets', name: 'targets', component: () => import('./pages/Targets.vue') },
  { path: '/targets/:id/:tab?', name: 'target', component: () => import('./pages/TargetDetail.vue'), props: true },
  { path: '/review', name: 'review', component: () => import('./pages/ReviewQueue.vue') },
  { path: '/runs', name: 'runs', component: () => import('./pages/Runs.vue') },
  { path: '/runs/:id', name: 'run', component: () => import('./pages/RunDetail.vue'), props: true },
  { path: '/suggestions', name: 'suggestions', component: () => import('./pages/Suggestions.vue') },
  { path: '/settings/:section?', name: 'settings', component: () => import('./pages/Settings.vue'), props: true },
  { path: '/ai', name: 'ai', component: () => import('./pages/AiUsage.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(app.basePath || '/'),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

createApp(App).use(router).mount('#app')
