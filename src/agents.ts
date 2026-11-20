/**
 * #689. The agent: a member of staff that holds seats but cannot act. Pickers
 * for anything somebody has to do themselves leave it out.
 */

/** Whether an id is an agent, as the page was told. */
export function isAgent( id: string | null | undefined ): boolean {
  return !! id && ( window.bwxForgeData?.agentIds ?? [] ).includes( id );
}

/** The people, leaving out any agent. */
export function withoutAgents< T extends { id: string } >( people: T[] ): T[] {
  return people.filter( ( person ) => ! isAgent( person.id ) );
}
