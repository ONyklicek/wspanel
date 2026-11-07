// A code editor like in an IDE: CodeMirror 6 with syntax highlighting, line
// numbers, folding, bracket matching, search/replace and multiple cursors, plus
// "Format" through Prettier. This file is small; CodeMirror and the languages
// load when an editor opens, Prettier when "Format" is used.

const formattable = ['php', 'javascript', 'typescript', 'json', 'css', 'html', 'yaml', 'markdown']
const dark = () => document.documentElement.classList.contains('dark')

function codeIde({ state, language, readOnly = false, minHeight = 400 }) {
    return {
        state,
        language,
        editor: null,
        core: null,
        loading: true,
        formatting: false,
        formatError: null,
        canFormat: !readOnly && formattable.includes(language),
        cursor: '1:1',

        async init() {
            this.core = await import('./code-ide-core.js')
            this.loading = false
            this.editor = this.core.createEditor({
                parent: this.$refs.editor,
                doc: this.state ?? '',
                language: this.language,
                readOnly,
                minHeight,
                dark: dark(),
                onChange: (text) => { this.state = text },
                onCursor: (c) => { this.cursor = c },
                onFormat: () => this.format(),
            })
            // The value may also change from the server (a reopened modal).
            this.$watch('state', (value) => {
                if ((value ?? '') !== this.editor.text()) this.editor.replace(value ?? '')
            })
            this.darkObserver = new MutationObserver(() => this.editor.setDark(dark()))
            this.darkObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
        },

        async format() {
            if (!this.canFormat || this.formatting || !this.editor) return
            this.formatting = true
            this.formatError = null
            try {
                this.editor.replace(await this.core.formatCode(this.language, this.editor.text()))
            } catch (e) {
                this.formatError = String(e.message || e).split('\n')[0]
            } finally {
                this.formatting = false
            }
        },

        destroy() {
            this.darkObserver?.disconnect()
            this.editor?.view.destroy()
        },
    }
}

let registered = false
const register = () => {
    if (registered || !window.Alpine) return
    registered = true
    window.Alpine.data('wspanelCodeIde', codeIde)
}
if (window.Alpine) register()
else document.addEventListener('alpine:init', register)
