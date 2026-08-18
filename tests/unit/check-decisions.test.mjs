import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkDecisions } from '../../bin/check-decisions.mjs';

const manifest = [
	{ id: 'ARCH-1', group: 'Architecture', question: 'Client delivery model' },
];

const complete = `
### ARCH-1 — client-delivery-model

**Question:** Embedded module, plugin, subdomain or separate application?

**Options considered:** One plugin in two modes; one repo with two zips; two repos.

**Decision:** One repo, two zips.

**Consequence if reversed:** The client artifact would contain studio code.
`;

test( 'passes when every manifest id is fully answered', () => {
	const result = checkDecisions( manifest, complete );
	assert.equal( result.ok, true );
	assert.deepEqual( result.problems, [] );
} );

test( 'fails when a manifest id is missing entirely', () => {
	const result = checkDecisions( manifest, '# Decisions\n' );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [ 'ARCH-1: no section found' ] );
} );

test( 'fails when a required part is missing', () => {
	const missingConsequence = complete.replace(
		/\*\*Consequence if reversed:\*\*.*/s,
		''
	);
	const result = checkDecisions( manifest, missingConsequence );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [
		'ARCH-1: missing "Consequence if reversed"',
	] );
} );

test( 'fails when a decision is left unanswered', () => {
	const deferred = complete.replace(
		'**Decision:** One repo, two zips.',
		'**Decision:** TBD'
	);
	const result = checkDecisions( manifest, deferred );
	assert.equal( result.ok, false );
	assert.deepEqual( result.problems, [
		'ARCH-1: "Decision" is a placeholder ("TBD")',
	] );
} );

test( 'accepts an explicit, scoped decision to defer', () => {
	const explicitDefer = complete.replace(
		'**Decision:** One repo, two zips.',
		'**Decision:** Deferred until Milestone 7. Blocks: capacity gate design.'
	);
	const result = checkDecisions( manifest, explicitDefer );
	assert.equal( result.ok, true );
} );
