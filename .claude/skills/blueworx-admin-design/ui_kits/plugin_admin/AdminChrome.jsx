import React from 'react';
import { Icon } from '../../components/core/Icon.jsx';

const CORE_MENU = [
  { id: 'dashboard-wp', label: 'Dashboard', icon: 'layout-dashboard' },
  { id: 'posts', label: 'Posts', icon: 'file-text' },
  { id: 'media', label: 'Media', icon: 'image' },
  { id: 'pages', label: 'Pages', icon: 'file' },
];
const TAIL_MENU = [
  { id: 'appearance', label: 'Appearance', icon: 'paintbrush' },
  { id: 'plugins', label: 'Plugins', icon: 'plug' },
  { id: 'users', label: 'Users', icon: 'users' },
  { id: 'settings', label: 'Settings', icon: 'settings' },
];

/** WordPress admin bar + left menu, with the plugin's own menu item expanded. */
export function AdminChrome({ pluginLabel = 'Example Plugin', pluginIcon = 'users', submenu = [], current, onNavigate, children }) {
  return (
    <div className="wp-shell">
      <div className="wp-adminbar">
        <span className="wp-adminbar__item"><Icon name="globe" size={16} /></span>
        <span className="wp-adminbar__item"><Icon name="house" size={16} />Example Site</span>
        <span className="wp-adminbar__item"><Icon name="refresh-cw" size={16} />2</span>
        <span className="wp-adminbar__item"><Icon name="message-square" size={16} />0</span>
        <span className="wp-adminbar__spacer" />
        <span className="wp-adminbar__item">Howdy, Luke</span>
      </div>
      <nav className="wp-menu" aria-label="WordPress admin menu">
        {CORE_MENU.map((m) => (
          <button key={m.id} type="button" className="wp-menu__item"><Icon name={m.icon} size={18} />{m.label}</button>
        ))}
        <div className="wp-menu__sep" />
        <button type="button" className="wp-menu__item is-current"><Icon name={pluginIcon} size={18} />{pluginLabel}</button>
        <div className="wp-submenu">
          {submenu.map((s) => (
            <button
              key={s.id}
              type="button"
              className={`wp-submenu__item ${s.id === current ? 'is-current' : ''}`}
              onClick={() => onNavigate && onNavigate(s.id)}
            >{s.label}</button>
          ))}
        </div>
        <div className="wp-menu__sep" />
        {TAIL_MENU.map((m) => (
          <button key={m.id} type="button" className="wp-menu__item"><Icon name={m.icon} size={18} />{m.label}</button>
        ))}
      </nav>
      <main className="wp-content">{children}</main>
    </div>
  );
}
