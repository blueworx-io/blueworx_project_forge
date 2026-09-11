/*
 * The shared component kit (#296). Both the studio app and the client app
 * build their screens from what is exported here, and from nothing styled
 * elsewhere — one kit, one design, two interfaces.
 */
export {
  Button,
  IconButton,
  Tag,
  StageChip,
  STAGE_LABELS,
  STAGE_ORDER,
  Card,
  Panel,
  Eyebrow,
  Stat,
  Avatar,
  Roles,
  IconTile,
  PageHeader,
  SectionTitle,
  EmptyState,
} from './primitives';
export type { ButtonVariant, ButtonSize, Tone, RolePerson, TileHue, Tab } from './primitives';

export { Field, TextInput, TextArea, Select, ReasonAction, Evidence } from './forms';

export { Modal, ToastProvider, useToast } from './overlays';
export type { ToastTone } from './overlays';

export { Meter } from './Meter';

export { DataView, Check, ViewPill, FilterPill, BulkButton } from './DataView';
export type { Column, SavedView, Filter, Sort } from './DataView';
