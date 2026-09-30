/**
 * 门禁检查项中文名映射（单一事实源，ReleaseGatePanel 与 VersionListPanel 共用）
 * key = 后端 ReleaseGateService 的 check.code
 */
export const GATE_LABELS = {
  version_metadata: '版本负责人与计划发布日期已设置',
  release_notes_present: '发布说明已填写',
  non_empty_scope: '版本范围非空',
  reviewed_assigned_scope: '范围内需求已审核且有执行负责人',
  project_delivery: '项目交付进度达到目标阶段',
  tasks_completed: '范围任务全部完成',
  severe_defects_closed: '严重缺陷全部关闭',
  acceptance_complete: '范围内需求全部验收完成',
}

export function gateLabel(code, fallback = '') {
  return GATE_LABELS[code] ?? fallback ?? code
}
