import { useEffect, useRef } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';
import { Bold, Italic, Link2, List, ListOrdered, RemoveFormatting, Underline } from 'lucide-react';

/*
 * A small text editor of our own (Luke, 2026-09-17: "basic text editing
 * functions", and no library for it). Bold, italics, underline, the two
 * kinds of list, a link, and a way back to plain text. It speaks HTML, and
 * only the HTML below: whatever the browser produces is walked and reduced
 * to that set before it leaves here, and the server reduces it again.
 *
 * Paste is plain text on purpose. A page pasted from somewhere else brings
 * its own fonts, colours and spans, none of which belong on a task.
 */

/** The tags that may leave the editor, and the one attribute among them. */
const ALLOWED = new Set( [ 'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a' ] );
const RENAMED: Record< string, string > = { b: 'strong', i: 'em', div: 'p' };

function safeHref( href: string ): string {
  const value = href.trim();

  return /^(https?:|mailto:)/i.test( value ) ? value : '';
}

/** Reduces whatever the browser made to the allowed set. */
export function cleanHtml( html: string ): string {
  const doc = new DOMParser().parseFromString( `<body>${ html }</body>`, 'text/html' );

  const walk = ( node: Node ): string => {
    if ( node.nodeType === Node.TEXT_NODE ) {
      return ( node.textContent ?? '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
    }

    if ( node.nodeType !== Node.ELEMENT_NODE ) {
      return '';
    }

    const element = node as HTMLElement;
    const inner = Array.from( element.childNodes ).map( walk ).join( '' );
    const tag = RENAMED[ element.tagName.toLowerCase() ] ?? element.tagName.toLowerCase();

    if ( ! ALLOWED.has( tag ) ) {
      return inner;
    }

    if ( 'br' === tag ) {
      return '<br>';
    }

    if ( 'a' === tag ) {
      const href = safeHref( element.getAttribute( 'href' ) ?? '' );

      return '' === href ? inner : `<a href="${ href.replace( /"/g, '&quot;' ) }">${ inner }</a>`;
    }

    if ( 'p' === tag && '' === inner.replace( /<br>/g, '' ).trim() ) {
      return '';
    }

    return `<${ tag }>${ inner }</${ tag }>`;
  };

  const out = Array.from( doc.body.childNodes ).map( walk ).join( '' );

  return '' === out.replace( /<[^>]+>/g, '' ).trim() && ! /<(ul|ol)>/.test( out ) ? '' : out;
}

/** A stored value as the editor shows it: HTML as it is, plain text as paragraphs. */
function toHtml( value: string ): string {
  if ( '' === value.trim() ) {
    return '';
  }

  // A value with a tag is trusted to be our own HTML almost everywhere it
  // comes from, but not everywhere (2026-09-25): reduced to the allowed set
  // before it is ever handed to innerHTML, the same as a person's own edits.
  if ( value.includes( '<' ) ) {
    return cleanHtml( value );
  }

  const escape = ( text: string ) => text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

  return value
    .split( /\n{2,}/ )
    .map( ( paragraph ) => `<p>${ escape( paragraph ).replace( /\n/g, '<br>' ) }</p>` )
    .join( '' );
}

const TOOLS: Array< { command: string; label: string; icon: typeof Bold } > = [
  { command: 'bold', label: 'Bold', icon: Bold },
  { command: 'italic', label: 'Italic', icon: Italic },
  { command: 'underline', label: 'Underline', icon: Underline },
  { command: 'insertUnorderedList', label: 'Bulleted list', icon: List },
  { command: 'insertOrderedList', label: 'Numbered list', icon: ListOrdered },
  { command: 'createLink', label: 'Link', icon: Link2 },
  { command: 'removeFormat', label: 'Clear formatting', icon: RemoveFormatting },
];

export function RichText( {
  id,
  value,
  onChange,
  label,
  testId,
  minHeight = 96,
}: {
  id?: string;
  value: string;
  onChange: ( html: string ) => void;
  label?: string;
  testId?: string;
  minHeight?: number;
} ) {
  const area = useRef< HTMLDivElement >( null );

  // The value in, but never under a person's cursor: while the area has
  // focus, what they are typing is the truth and the prop follows it.
  useEffect( () => {
    const node = area.current;

    if ( ! node || document.activeElement === node ) {
      return;
    }

    const shown = cleanHtml( node.innerHTML );

    if ( shown !== cleanHtml( toHtml( value ) ) ) {
      node.innerHTML = toHtml( value );
    }
  }, [ value ] );

  const emit = () => {
    if ( area.current ) {
      onChange( cleanHtml( area.current.innerHTML ) );
    }
  };

  const run = ( command: string ) => {
    area.current?.focus();

    if ( 'createLink' === command ) {
      const href = safeHref( window.prompt( 'Link address' ) ?? '' );

      if ( '' === href ) {
        return;
      }

      document.execCommand( 'createLink', false, href );
    } else {
      document.execCommand( command );
    }

    emit();
  };

  const keys = ( event: KeyboardEvent< HTMLDivElement > ) => {
    if ( ! ( event.ctrlKey || event.metaKey ) ) {
      return;
    }

    const command = { b: 'bold', i: 'italic', u: 'underline' }[ event.key.toLowerCase() ];

    if ( command ) {
      event.preventDefault();
      run( command );
    }
  };

  const paste = ( event: ClipboardEvent< HTMLDivElement > ) => {
    event.preventDefault();
    document.execCommand( 'insertText', false, event.clipboardData.getData( 'text/plain' ) );
    emit();
  };

  return (
    <div className="fk-rich">
      <div className="fk-rich-tools" role="toolbar" aria-label={ label ? `${ label } formatting` : 'Formatting' }>
        { TOOLS.map( ( tool ) => (
          <button
            key={ tool.command }
            type="button"
            className="fk-rich-tool"
            aria-label={ tool.label }
            title={ tool.label }
            onMouseDown={ ( event ) => event.preventDefault() }
            onClick={ () => run( tool.command ) }
          >
            <tool.icon size={ 15 } strokeWidth={ 1.75 } aria-hidden="true" />
          </button>
        ) ) }
      </div>
      <div
        ref={ area }
        id={ id }
        className="fk-rich-area"
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label={ label }
        data-testid={ testId }
        style={ { minHeight } }
        onInput={ emit }
        onBlur={ emit }
        onKeyDown={ keys }
        onPaste={ paste }
      />
    </div>
  );
}
