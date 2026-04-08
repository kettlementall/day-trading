<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">系統規格</h1>
    </div>
    <el-skeleton v-if="loading" :rows="10" animated />
    <div v-else class="spec-content" v-html="renderedHtml"></div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { getSpec } from '../api'

const loading = ref(false)
const renderedHtml = ref('')

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await getSpec()
    renderedHtml.value = renderMarkdown(data.content)
  } catch {
    renderedHtml.value = '<p>無法載入規格文件</p>'
  } finally {
    loading.value = false
  }
})

function renderMarkdown(md) {
  const lines = md.split('\n')
  let html = ''
  let inTable = false
  let inCode = false
  let codeBlock = ''

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]

    // Code block
    if (line.startsWith('```')) {
      if (inCode) {
        html += `<pre><code>${escapeHtml(codeBlock.trim())}</code></pre>`
        codeBlock = ''
        inCode = false
      } else {
        inCode = true
      }
      continue
    }
    if (inCode) {
      codeBlock += line + '\n'
      continue
    }

    // Table
    if (line.includes('|') && line.trim().startsWith('|')) {
      const cells = line.split('|').slice(1, -1).map(c => c.trim())
      // Skip separator row
      if (cells.every(c => /^[-:]+$/.test(c))) continue
      if (!inTable) {
        html += '<div class="table-wrap"><table>'
        inTable = true
        html += '<thead><tr>' + cells.map(c => `<th>${escapeHtml(c)}</th>`).join('') + '</tr></thead><tbody>'
        continue
      }
      html += '<tr>' + cells.map(c => `<td>${formatInline(escapeHtml(c))}</td>`).join('') + '</tr>'
      continue
    }
    if (inTable) {
      html += '</tbody></table></div>'
      inTable = false
    }

    // Headings
    const hMatch = line.match(/^(#{1,6})\s+(.+)/)
    if (hMatch) {
      const level = hMatch[1].length
      html += `<h${level}>${escapeHtml(hMatch[2])}</h${level}>`
      continue
    }

    // Horizontal rule
    if (/^---+$/.test(line.trim())) {
      html += '<hr>'
      continue
    }

    // Blockquote
    if (line.startsWith('> ')) {
      html += `<blockquote>${escapeHtml(line.slice(2))}</blockquote>`
      continue
    }

    // List item
    if (/^[-*]\s/.test(line.trim())) {
      html += `<div class="list-item">${formatInline(escapeHtml(line.trim().slice(2)))}</div>`
      continue
    }

    // Numbered list
    const olMatch = line.trim().match(/^(\d+)\.\s(.+)/)
    if (olMatch) {
      html += `<div class="list-item"><strong>${olMatch[1]}.</strong> ${formatInline(escapeHtml(olMatch[2]))}</div>`
      continue
    }

    // Empty line
    if (line.trim() === '') {
      continue
    }

    // Paragraph
    html += `<p>${formatInline(escapeHtml(line))}</p>`
  }

  if (inTable) html += '</tbody></table></div>'
  return html
}

function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function formatInline(str) {
  return str
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
}
</script>

<style scoped>
.spec-content {
  font-size: 13px;
  line-height: 1.7;
  color: #303133;
}

.spec-content :deep(h1) {
  font-size: 20px;
  font-weight: 700;
  margin: 20px 0 8px;
  padding-bottom: 6px;
  border-bottom: 2px solid #409eff;
}

.spec-content :deep(h2) {
  font-size: 16px;
  font-weight: 700;
  margin: 18px 0 8px;
  padding-bottom: 4px;
  border-bottom: 1px solid #e4e7ed;
}

.spec-content :deep(h3) {
  font-size: 14px;
  font-weight: 700;
  margin: 14px 0 6px;
}

.spec-content :deep(h4) {
  font-size: 13px;
  font-weight: 700;
  margin: 12px 0 4px;
}

.spec-content :deep(hr) {
  border: none;
  border-top: 1px solid #e4e7ed;
  margin: 16px 0;
}

.spec-content :deep(p) {
  margin: 4px 0;
}

.spec-content :deep(blockquote) {
  margin: 8px 0;
  padding: 8px 12px;
  border-left: 3px solid #409eff;
  background: #f5f7fa;
  border-radius: 0 4px 4px 0;
  color: #606266;
  font-size: 12px;
}

.spec-content :deep(.table-wrap) {
  overflow-x: auto;
  margin: 8px 0;
}

.spec-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.spec-content :deep(th) {
  background: #f5f7fa;
  font-weight: 600;
  text-align: left;
  padding: 6px 8px;
  border: 1px solid #e4e7ed;
  white-space: nowrap;
}

.spec-content :deep(td) {
  padding: 5px 8px;
  border: 1px solid #e4e7ed;
}

.spec-content :deep(tr:hover td) {
  background: #f5f7fa;
}

.spec-content :deep(pre) {
  background: #1e1e1e;
  color: #d4d4d4;
  padding: 12px;
  border-radius: 6px;
  overflow-x: auto;
  font-size: 12px;
  line-height: 1.5;
  margin: 8px 0;
}

.spec-content :deep(code) {
  background: #f0f2f5;
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 12px;
  color: #c41a16;
}

.spec-content :deep(pre code) {
  background: none;
  padding: 0;
  color: inherit;
}

.spec-content :deep(.list-item) {
  padding: 2px 0 2px 16px;
  position: relative;
}

.spec-content :deep(.list-item::before) {
  content: '•';
  position: absolute;
  left: 4px;
  color: #909399;
}
</style>
