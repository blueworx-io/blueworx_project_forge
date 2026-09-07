import React from 'react';
import { Icon } from '../core/Icon.jsx';
import { Button } from '../core/Button.jsx';

export function UploadField({ title = 'Drop a file here', hint = 'CSV up to 5 MB.', file, accept, buttonLabel = 'Choose file', onChoose, onRemove, className = '' }) {
  const [over, setOver] = React.useState(false);
  return (
    <div
      className={['bw-upload', over ? 'is-over' : '', className].filter(Boolean).join(' ')}
      onDragOver={(e) => { e.preventDefault(); setOver(true); }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => { e.preventDefault(); setOver(false); onChoose && onChoose(); }}
    >
      <Icon name={file ? 'file-spreadsheet' : 'upload'} size={22} className="bw-upload__icon" />
      <div className="bw-upload__body">
        <p className="bw-upload__title">{file || title}</p>
        <p className="bw-upload__hint">{hint}</p>
      </div>
      {file
        ? <Button size="sm" variant="link" onClick={onRemove}>Remove</Button>
        : <Button size="sm" onClick={onChoose}>{buttonLabel}</Button>}
      {accept ? <input type="file" accept={accept} hidden readOnly /> : null}
    </div>
  );
}
