import React from 'react';
import { AdminChrome } from './AdminChrome.jsx';
import { DashboardScreen } from './DashboardScreen.jsx';
import { MembersScreen } from './MembersScreen.jsx';
import { SettingsScreen } from './SettingsScreen.jsx';
import { ToolsScreen } from './ToolsScreen.jsx';

const SUBMENU = [
  { id: 'dashboard', label: 'Overview' },
  { id: 'members', label: 'Members' },
  { id: 'settings', label: 'Settings' },
  { id: 'tools', label: 'Tools' },
];

export function PluginAdminApp() {
  const [screen, setScreen] = React.useState('dashboard');
  return (
    <AdminChrome pluginLabel="Example Plugin" pluginIcon="users" submenu={SUBMENU} current={screen} onNavigate={setScreen}>
      <div className="bw-admin bw-page">
        {screen === 'dashboard' ? <DashboardScreen onNavigate={(s) => setScreen(s === 'setup' ? 'settings' : s)} /> : null}
        {screen === 'members' ? <MembersScreen /> : null}
        {screen === 'settings' ? <SettingsScreen /> : null}
        {screen === 'tools' ? <ToolsScreen /> : null}
      </div>
    </AdminChrome>
  );
}
