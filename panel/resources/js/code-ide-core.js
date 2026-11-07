// The heavy half of the editor (CodeMirror + languages), loaded when an editor opens.
import { EditorView, basicSetup } from 'codemirror'
import { EditorState, Compartment } from '@codemirror/state'
import { keymap } from '@codemirror/view'
import { indentWithTab } from '@codemirror/commands'
import { StreamLanguage, indentUnit } from '@codemirror/language'
import { oneDark } from '@codemirror/theme-one-dark'
import { php } from '@codemirror/lang-php'
import { javascript } from '@codemirror/lang-javascript'
import { css } from '@codemirror/lang-css'
import { html } from '@codemirror/lang-html'
import { json } from '@codemirror/lang-json'
import { yaml } from '@codemirror/lang-yaml'
import { markdown } from '@codemirror/lang-markdown'
import { sql } from '@codemirror/lang-sql'
import { shell } from '@codemirror/legacy-modes/mode/shell'
import { properties } from '@codemirror/legacy-modes/mode/properties'
import { xml } from '@codemirror/legacy-modes/mode/xml'

const languages = {
    php: () => php(),
    javascript: () => javascript(),
    typescript: () => javascript({ typescript: true }),
    css: () => css(),
    html: () => html(),
    json: () => json(),
    yaml: () => yaml(),
    markdown: () => markdown(),
    sql: () => sql(),
    shell: () => StreamLanguage.define(shell),
    dotenv: () => StreamLanguage.define(properties),
    xml: () => StreamLanguage.define(xml),
}

// Prettier parsers per language; PHP through @prettier/plugin-php.
const prettierSetup = {
    php: async () => ({ parser: 'php', plugins: [await import('@prettier/plugin-php/standalone')] }),
    javascript: async () => ({ parser: 'babel', plugins: [await import('prettier/plugins/babel'), await import('prettier/plugins/estree')] }),
    typescript: async () => ({ parser: 'typescript', plugins: [await import('prettier/plugins/typescript'), await import('prettier/plugins/estree')] }),
    json: async () => ({ parser: 'json', plugins: [await import('prettier/plugins/babel'), await import('prettier/plugins/estree')] }),
    css: async () => ({ parser: 'css', plugins: [await import('prettier/plugins/postcss')] }),
    html: async () => ({ parser: 'html', plugins: [await import('prettier/plugins/html')] }),
    yaml: async () => ({ parser: 'yaml', plugins: [await import('prettier/plugins/yaml')] }),
    markdown: async () => ({ parser: 'markdown', plugins: [await import('prettier/plugins/markdown')] }),
}


export function createEditor({ parent, doc, language, readOnly, minHeight, dark, onChange, onCursor, onFormat }) {
    const theme = new Compartment()
    const lang = languages[language]?.()
    const view = new EditorView({
        parent,
        state: EditorState.create({
            doc,
            extensions: [
                basicSetup,
                keymap.of([indentWithTab]),
                indentUnit.of('    '),
                EditorState.tabSize.of(4),
                EditorState.readOnly.of(readOnly),
                lang ? [lang] : [],
                theme.of(dark ? oneDark : []),
                EditorView.theme({
                    '&': { minHeight: minHeight + 'px', fontSize: '13px' },
                    '.cm-scroller': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', minHeight: minHeight + 'px' },
                }),
                EditorView.updateListener.of((u) => {
                    if (u.docChanged) onChange(u.state.doc.toString())
                    if (u.selectionSet || u.docChanged) {
                        const pos = u.state.selection.main.head
                        const line = u.state.doc.lineAt(pos)
                        onCursor(line.number + ':' + (pos - line.from + 1))
                    }
                }),
                keymap.of([{ key: 'Shift-Alt-f', run: () => { onFormat(); return true } }]),
            ],
        }),
    })
    return {
        view,
        setDark: (on) => view.dispatch({ effects: theme.reconfigure(on ? oneDark : []) }),
        replace: (text) => view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } }),
        text: () => view.state.doc.toString(),
    }
}

export async function formatCode(language, text) {
    const prettier = await import('prettier/standalone')
    const setup = await prettierSetup[language]()
    return prettier.format(text, {
        ...setup,
        plugins: setup.plugins.map((m) => m.default ?? m),
        tabWidth: 4,
        printWidth: 120,
        phpVersion: '8.4',
        braceStyle: 'per-cs',
        singleQuote: language === 'php' || language === 'javascript',
    })
}

export const formattable = Object.keys(prettierSetup)
