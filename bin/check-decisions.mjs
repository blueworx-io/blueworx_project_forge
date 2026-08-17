#!/usr/bin/env node
/**
 * Verifies the decision record answers every decision in the manifest.
 *
 * The manifest is the list of questions that must be settled; the record is the
 * prose. Splitting them means adding a question fails CI until it is answered.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const PARTS = [
	'Question',
	'Options considered',
	'Decision',
	'Consequence if reversed',
];

const PLACEHOLDERS = [ 'TBD', 'TODO', 'to be confirmed', 'decision required' ];

/**
 * @param {Array<{id: string}>} manifest
 * @param {string} record
 * @returns {{ok: boolean, problems: string[]}}
 */
export function checkDecisions( manifest, record ) {
	const problems = [];
	const sections = splitSections( record );

	for ( const { id } of manifest ) {
		const body = sections.get( id );

		if ( undefined === body ) {
			problems.push( `${ id }: no section found` );
			continue;
		}

		for ( const part of PARTS ) {
			const value = extractPart( body, part );

			if ( null === value ) {
				problems.push( `${ id }: missing "${ part }"` );
				continue;
			}

			const hit = PLACEHOLDERS.find(
				( p ) => value.toLowerCase() === p.toLowerCase()
			);

			if ( hit ) {
				problems.push( `${ id }: "${ part }" is a placeholder ("${ hit }")` );
			}
		}
	}

	return { ok: 0 === problems.length, problems };
}

/**
 * @param {string} record
 * @returns {Map<string, string>}
 */
function splitSections( record ) {
	const sections = new Map();
	const pattern = /^### ([A-Z]+-\d+) — .*$/gm;
	const matches = [ ...record.matchAll( pattern ) ];

	matches.forEach( ( match, index ) => {
		const start = match.index + match[ 0 ].length;
		const end =
			index + 1 < matches.length ? matches[ index + 1 ].index : record.length;
		sections.set( match[ 1 ], record.slice( start, end ) );
	} );

	return sections;
}

/**
 * @param {string} body
 * @param {string} part
 * @returns {string|null}
 */
function extractPart( body, part ) {
	const pattern = new RegExp( `\\*\\*${ part }:\\*\\*([\\s\\S]*?)(?=\\n\\*\\*|$)` );
	const match = body.match( pattern );

	if ( ! match ) {
		return null;
	}

	const value = match[ 1 ].trim();

	return '' === value ? null : value;
}

const isDirectRun =
	process.argv[ 1 ] && process.argv[ 1 ] === fileURLToPath( import.meta.url );

if ( isDirectRun ) {
	const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
	const manifest = JSON.parse(
		readFileSync( join( root, 'docs/architecture/decisions-manifest.json' ), 'utf8' )
	);
	const record = readFileSync(
		join( root, 'docs/architecture/decisions.md' ),
		'utf8'
	);
	const { ok, problems } = checkDecisions( manifest.decisions, record );

	if ( ! ok ) {
		console.error( 'Decision record incomplete:\n' );
		problems.forEach( ( p ) => console.error( `  - ${ p }` ) );
		console.error(
			`\n${ problems.length } problem(s). Milestone 1 must not start until these are answered.`
		);
		process.exit( 1 );
	}

	console.log(
		`Decision record complete: ${ manifest.decisions.length } decisions answered.`
	);
}
