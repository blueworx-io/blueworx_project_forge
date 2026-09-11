import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Columns3, Inbox, Receipt } from 'lucide-react';
import {
  Button,
  BulkButton,
  Card,
  DataView,
  EmptyState,
  Evidence,
  Field,
  IconTile,
  Meter,
  Modal,
  PageHeader,
  Panel,
  ReasonAction,
  Roles,
  SectionTitle,
  Stat,
  StageChip,
  STAGE_ORDER,
  Tag,
  TextInput,
  ToastProvider,
  useToast,
  type Filter,
} from './index';

/*
 * Every component in the kit, on one page (#296). Reached from the app page
 * with `#kit` — it is the kit's own proof, and what the Playwright spec and
 * the accessibility check read. Nothing here talks to the server.
 */

interface Row extends Record< string, unknown > {
  id: string;
  title: string;
  client: string;
  stage: string;
  hours: number;
  due: string;
}

const ROWS: Row[] = [
  { id: 'FRG-2841', title: 'Rebuild member area authentication', client: 'Northgate', stage: 'in-development', hours: 14, due: '12 Aug 2026' },
  { id: 'FRG-2790', title: 'Reserve deposit hours against booking items', client: 'Halden Cove', stage: 'up-next', hours: 8, due: '21 Aug 2026' },
  { id: 'FRG-2712', title: 'DNS delegation for staging subdomain', client: 'Northgate', stage: 'blocked', hours: 3, due: '09 Aug 2026' },
  { id: 'FRG-2688', title: 'Consent copy review with compliance', client: 'Verity Health', stage: 'in-review', hours: 6, due: '18 Aug 2026' },
  { id: 'FRG-2650', title: 'Migrate legacy redirect inventory', client: 'Halden Cove', stage: 'completed', hours: 5, due: '15 Aug 2026' },
  { id: 'FRG-2601', title: 'Accessibility audit remediation pass', client: 'Verity Health', stage: 'technical-audit', hours: 10, due: '26 Aug 2026' },
];

function Section( { id, title, children }: { id: string; title: string; children: ReactNode } ) {
  return (
    <section data-section={ id } style={ { display: 'flex', flexDirection: 'column', gap: 16 } }>
      <h2 className="fk-eyebrow" data-tone="brand">
        { title }
      </h2>
      { children }
    </section>
  );
}

function Row( { children }: { children: ReactNode } ) {
  return <div style={ { display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' } }>{ children }</div>;
}

function DataViewDemo() {
  const [ view, setView ] = useState( 'everything' );
  const [ search, setSearch ] = useState( '' );
  const [ filters, setFilters ] = useState< Filter[] >( [
    { id: 'client', label: 'Client', options: [ 'Any client', 'Northgate', 'Halden Cove', 'Verity Health' ] },
  ] );
  const [ selection, setSelection ] = useState< Array< string | number > >( [] );

  const rows = useMemo( () => {
    const q = search.trim().toLowerCase();
    return ROWS.filter( ( row ) => {
      if ( 'blocked' === view && 'blocked' !== row.stage ) return false;
      if ( 'delivery' === view && ! [ 'up-next', 'in-development', 'in-review' ].includes( row.stage ) ) return false;
      const client = filters[ 0 ].value;
      if ( client && row.client !== client ) return false;
      if ( q && ! `${ row.id } ${ row.title } ${ row.client }`.toLowerCase().includes( q ) ) return false;
      return true;
    } );
  }, [ view, search, filters ] );

  return (
    <DataView< Row >
      title="Work"
      views={ [
        { id: 'everything', label: 'Everything', count: ROWS.length },
        { id: 'delivery', label: 'In delivery', count: ROWS.filter( ( r ) => [ 'up-next', 'in-development', 'in-review' ].includes( r.stage ) ).length },
        { id: 'blocked', label: 'Blocked', count: ROWS.filter( ( r ) => 'blocked' === r.stage ).length },
      ] }
      activeView={ view }
      onViewChange={ setView }
      search={ search }
      onSearch={ setSearch }
      searchPlaceholder="Search work"
      filters={ filters }
      onFilter={ ( id, value ) => setFilters( ( list ) => list.map( ( f ) => ( f.id === id ? { ...f, value } : f ) ) ) }
      onClearFilters={ () => setFilters( ( list ) => list.map( ( f ) => ( { ...f, value: null } ) ) ) }
      selectable
      selection={ selection }
      onSelectionChange={ setSelection }
      bulkActions={
        <>
          <BulkButton>Assign</BulkButton>
          <BulkButton>Decline with reason</BulkButton>
        </>
      }
      columns={ [
        { key: 'id', label: 'ID', mono: true },
        { key: 'title', label: 'Task', wrap: true },
        { key: 'client', label: 'Client' },
        { key: 'stage', label: 'Stage', render: ( row ) => <StageChip stage={ row.stage } dense /> },
        { key: 'hours', label: 'Hours', mono: true, align: 'right', render: ( row ) => `${ row.hours.toFixed( 1 ) }h` },
        { key: 'due', label: 'Due', mono: true },
      ] }
      rows={ rows }
      onRowClick={ () => {} }
      footer={
        <>
          <span>
            { rows.length } of { ROWS.length } tasks
          </span>
          <span>Click a row to open it.</span>
        </>
      }
    />
  );
}

function DialogDemo() {
  const [ open, setOpen ] = useState( false );
  const toast = useToast();

  return (
    <Row>
      <Button variant="secondary" onClick={ () => setOpen( true ) }>
        Open a dialog
      </Button>
      { open && (
        <Modal
          title="Return with feedback"
          description="FRG-2841 · Rebuild member area authentication"
          onClose={ () => setOpen( false ) }
          footer={
            <Button variant="secondary" onClick={ () => setOpen( false ) }>
              Cancel
            </Button>
          }
        >
          <ReasonAction
            label="Return with feedback"
            placeholder="What is missing, and what would make it ready."
            onSubmit={ () => {
              setOpen( false );
              toast( 'FRG-2841 returned to In Development' );
            } }
          />
        </Modal>
      ) }
    </Row>
  );
}

function ToastDemo() {
  const toast = useToast();
  return (
    <Row>
      <Button variant="secondary" onClick={ () => toast( 'Comment added to FRG-2841' ) }>
        Show a toast
      </Button>
      <Button variant="secondary" onClick={ () => toast( 'Transition blocked — 2 gate requirements missing', 'danger' ) }>
        Show a failure
      </Button>
    </Row>
  );
}

function EvidenceDemo() {
  const [ files, setFiles ] = useState< string[] >( [] );
  return <Evidence files={ files } onAdd={ ( picked ) => setFiles( ( list ) => list.concat( picked.map( ( f ) => f.name ) ) ) } />;
}

export function Gallery() {
  return (
    <ToastProvider stamp={ () => '14 Aug 10:42 BST · R. Ilesanmi · Primary Site' }>
      <main data-testid="fk-gallery" style={ { padding: 32, display: 'flex', flexDirection: 'column', gap: 48, maxWidth: 1120, margin: '0 auto' } }>
        <h1 className="fk-page-title">Forge component kit</h1>

        <Section id="buttons" title="Buttons">
          <Row>
            <Button>Request transition</Button>
            <Button variant="secondary">Assign hours</Button>
            <Button variant="soft">New task</Button>
            <Button variant="ghost">Cancel</Button>
            <Button variant="danger">Decline</Button>
            <Button disabled>Return with feedback</Button>
          </Row>
          <Row>
            <Button size="sm">Small</Button>
            <Button size="md">Medium</Button>
            <Button size="lg">Large</Button>
          </Row>
        </Section>

        <Section id="tags" title="Tags">
          <Row>
            <Tag>Neutral</Tag>
            <Tag tone="ok" dot>
              On track
            </Tag>
            <Tag tone="warn" dot>
              At risk
            </Tag>
            <Tag tone="danger">Blocked</Tag>
            <Tag tone="info">Converted</Tag>
            <Tag tone="brand">Launch critical</Tag>
            <Tag tone="warn" wrap>
              More information required
            </Tag>
          </Row>
        </Section>

        <Section id="stage-chips" title="Stage chips">
          <Row>
            { STAGE_ORDER.map( ( stage ) => (
              <StageChip key={ stage } stage={ stage } />
            ) ) }
          </Row>
        </Section>

        <Section id="cards" title="Cards, panels and stats">
          <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 16 } }>
            <Card>
              <Stat label="Hours available" value="62.5h" sub="of 90h this period" tone="ok" />
            </Card>
            <Card tone="sunken">
              <Stat label="Over-allocated" value="1" sub="A. Whitfield, this week" tone="danger" />
            </Card>
            <Card tone="tint">
              <Stat label="Needs you" value="3" sub="2 overdue" tone="warn" />
            </Card>
            <Panel title="Exit gate" right={ <Tag tone="warn">2 missing</Tag> }>
              <p style={ { margin: 0 } }>Add test evidence before requesting review.</p>
            </Panel>
          </div>
        </Section>

        <Section id="page-header" title="Page header">
          <PageHeader
            crumbs={ [ 'Delivery', 'Kanban', 'FRG-2841' ] }
            eyebrow="Task"
            title="Rebuild member area authentication"
            description="Northgate · Milestone · Launch v1"
            tile={ Columns3 }
            actions={ <Button>Request transition</Button> }
            meta={
              <>
                <span>Owner M. Okonkwo</span>
                <span>Due 12 Aug 2026</span>
              </>
            }
            tabs={ [
              { id: 'record', label: 'Record' },
              { id: 'history', label: 'Changelog', count: 14 },
            ] }
            activeTab="record"
          />
          <SectionTitle sub="Everything on your account, in one place. You can add information and raise requests here — moving work along is our job.">
            Dashboard
          </SectionTitle>
        </Section>

        <Section id="dataview" title="Data view">
          <DataViewDemo />
        </Section>

        <Section id="dialog" title="Dialog and reason-gated action">
          <DialogDemo />
        </Section>

        <Section id="fields" title="Fields">
          <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))', gap: 16 } }>
            <Field label="Title" required help="One line, as the studio will read it.">
              { ( id ) => <TextInput id={ id } placeholder="What needs doing" /> }
            </Field>
            <Field label="Evidence">{ () => <EvidenceDemo /> }</Field>
          </div>
        </Section>

        <Section id="meter" title="Hours meter">
          <Card>
            <Meter allocation={ 90 } used={ 22.5 } reserved={ 5 } label="Support hours this period" note="Renews 1 Oct 2026" />
          </Card>
        </Section>

        <Section id="people" title="Avatars and roles">
          <Card>
            <Roles owner={ { name: 'M. Okonkwo', hours: 12 } } checker={ { name: 'R. Ilesanmi', hours: 2 } } builder={ null } hours />
          </Card>
          <Row>
            <IconTile icon={ Receipt } hue="amber" label="Packages" />
            <IconTile icon={ Inbox } hue="violet" size="sm" label="Requests" />
            <IconTile icon={ Columns3 } hue="blue" size="lg" label="Kanban" />
          </Row>
        </Section>

        <Section id="toast" title="Toasts">
          <ToastDemo />
        </Section>

        <Section id="empty-state" title="Empty state">
          <Card>
            <EmptyState
              icon={ Inbox }
              title="Nothing waiting for review"
              body="Submissions land here as clients raise them. The next one will show the moment it arrives."
              action={ <Button variant="secondary">Raise one for a client</Button> }
            />
          </Card>
        </Section>
      </main>
    </ToastProvider>
  );
}
