import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useSidebarStore = defineStore('sidebar', () => {
  // ======= State =======
  const collapsed = ref(false)
  const activeMenu = ref('')

  // ======= Actions =======
  function toggleCollapse() {
    collapsed.value = !collapsed.value
  }

  function setActiveMenu(menu) {
    activeMenu.value = menu
  }

  return {
    // State
    collapsed,
    activeMenu,
    // Actions
    toggleCollapse,
    setActiveMenu
  }
})
