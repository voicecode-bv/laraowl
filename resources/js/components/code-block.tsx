import type { CSSProperties } from 'react';
import json from 'react-syntax-highlighter/dist/esm/languages/prism/json';
import php from 'react-syntax-highlighter/dist/esm/languages/prism/php';
import SyntaxHighlighter from 'react-syntax-highlighter/dist/esm/prism-light';

/**
 * Syntax highlighting for the two languages this app actually renders.
 *
 * The default `Prism` export of react-syntax-highlighter carries every
 * language it supports — around 600 kB of JavaScript, more than the rest of
 * the application put together — for pages that only ever show a JSON payload
 * or a PHP stack frame. The light build ships the highlighter alone and lets
 * us register those two.
 */
SyntaxHighlighter.registerLanguage('json', json);
SyntaxHighlighter.registerLanguage('php', php);

export type CodeLanguage = 'json' | 'php';

export function CodeBlock({
    language,
    style,
    customStyle,
    wrapLines = true,
    wrapLongLines = true,
    showLineNumbers,
    startingLineNumber,
    lineProps,
    children,
}: {
    language: CodeLanguage;
    style: Record<string, CSSProperties>;
    customStyle?: CSSProperties;
    wrapLines?: boolean;
    wrapLongLines?: boolean;
    showLineNumbers?: boolean;
    startingLineNumber?: number;
    lineProps?: (lineNumber: number) => Record<string, unknown>;
    children: string;
}) {
    return (
        <SyntaxHighlighter
            language={language}
            style={style}
            customStyle={customStyle}
            wrapLines={wrapLines}
            wrapLongLines={wrapLongLines}
            showLineNumbers={showLineNumbers}
            startingLineNumber={startingLineNumber}
            lineProps={lineProps}
        >
            {children}
        </SyntaxHighlighter>
    );
}
