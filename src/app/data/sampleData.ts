import { Feature, SubItem, Bug, Feedback, Release, Item, CompanyDate } from '../types';

export const sampleCompanyDates: CompanyDate[] = [
  { id: 'cd1', title: 'Q3 Planning Summit', date: '2026-06-15', description: 'All-hands meeting for Q3 strategy.', tracked: true },
  { id: 'cd2', title: 'Company Holiday', date: '2026-06-19', description: 'Juneteenth observance.', tracked: false },
  { id: 'cd3', title: 'End of Q2', date: '2026-06-30', tracked: true },
  { id: 'cd4', title: 'Summer Retreat', date: '2026-07-20' },
  { id: 'cd5', title: 'Product Launch Day', date: '2026-08-10', description: 'Marketing campaign go-live.' },
];

export const sampleFeatures: Feature[] = [
  {
    id: 'f1', type: 'feature', name: 'User Authentication System',
    description: 'Implement OAuth 2.0 authentication with support for Google, GitHub, and Microsoft providers. Include session management and refresh token handling.',
    workflowStage: 'in-development', category: 'Security', featurePrice: 'premium', timeEstimate: 40,
    releaseId: 'r1', subItemIds: ['s1', 's2'], isEnabled: true, isTrackedAsStat: true, createdDate: '2026-04-15',
    images: [
      'https://images.unsplash.com/photo-1633265486064-086b219458ec?w=800',
      'https://images.unsplash.com/photo-1614064641938-3bbee52942c7?w=800',
    ],
  },
  {
    id: 'f2', type: 'feature', name: 'Dashboard Performance Optimization',
    description: 'Optimize dashboard loading time by implementing lazy loading, caching strategies, and reducing initial bundle size.',
    workflowStage: 'in-development', category: 'Performance', featurePrice: 'free', timeEstimate: 24,
    releaseId: 'r1', isEnabled: true, isTrackedAsStat: true, createdDate: '2026-04-20',
  },
  {
    id: 'f3', type: 'feature', name: 'Dark Mode Support',
    description: 'Add comprehensive dark mode theme with automatic detection and manual toggle. Ensure all components support both themes.',
    workflowStage: 'up-next', category: 'UI/UX', featurePrice: 'teaser', timeEstimate: 16,
    releaseId: 'r1', isEnabled: false, isTrackedAsStat: false, createdDate: '2026-05-01',
    images: ['https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=800'],
  },
  {
    id: 'f4', type: 'feature', name: 'Export to Multiple Formats',
    description: 'Allow users to export project data to CSV, JSON, Excel, and PDF formats with customizable field selection.',
    workflowStage: 'scoping', category: 'Data Management', featurePrice: 'scoping', timeEstimate: 20,
    releaseId: 'r2', isEnabled: false, isTrackedAsStat: false, createdDate: '2026-05-10',
  },
  {
    id: 'f5', type: 'feature', name: 'Email Notification System',
    description: 'Send email notifications for important project updates, deadlines, and status changes.',
    workflowStage: 'future-idea', category: 'Communication', featurePrice: 'premium', timeEstimate: 28,
    isEnabled: false, isTrackedAsStat: false, createdDate: '2026-05-15',
  },
  {
    id: 'f6', type: 'feature', name: 'Advanced Search & Filters',
    description: 'Implement comprehensive search with filters, saved searches, and full-text search capabilities.',
    workflowStage: 'staging-features', category: 'Search', featurePrice: 'premium', timeEstimate: 32,
    releaseId: 'r2', isEnabled: true, isTrackedAsStat: true, createdDate: '2026-04-01',
  },
  {
    id: 'f7', type: 'feature', name: 'Mobile App Integration',
    description: 'Native mobile app with offline support and push notifications.',
    workflowStage: 'active-features', category: 'Mobile', featurePrice: 'premium', timeEstimate: 120,
    isEnabled: true, isTrackedAsStat: true, createdDate: '2026-02-01',
  },
];

export const sampleSubItems: SubItem[] = [
  {
    id: 's1', type: 'subitem', name: 'OAuth Provider Integration',
    description: 'Integrate Google, GitHub, and Microsoft OAuth providers with proper error handling.',
    parentFeatureId: 'f1', workflowStage: 'in-development', category: 'Security', featurePrice: 'premium', timeEstimate: 20, releaseId: 'r1',
  },
  {
    id: 's2', type: 'subitem', name: 'Session Management',
    description: 'Implement secure session handling with refresh token rotation and automatic session cleanup.',
    parentFeatureId: 'f1', workflowStage: 'in-development', category: 'Security', featurePrice: 'premium', timeEstimate: 20, releaseId: 'r1',
  },
];

export const sampleBugs: Bug[] = [
  {
    id: 'b1', type: 'bug', title: 'Login redirect 404 error',
    description: 'Users are redirected to 404 page after successful login instead of dashboard. Occurs in production environment only.',
    linkedFeatureId: 'f1', releaseId: 'r1', bugStatus: 'in-progress', workflowStage: 'bug-tracking', priority: 'high',
    timeEstimate: 4, reportedDate: '2026-05-20',
    notes: 'Appears to be related to routing configuration in production build.',
    images: [
      'https://images.unsplash.com/photo-1563206767-5b18f218e8de?w=800',
      'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800',
      'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=800',
    ],
    urls: ['https://github.com/example/issue/123', 'https://errorlogs.example.com/abc123'],
  },
  {
    id: 'b2', type: 'bug', title: 'Memory leak in data grid',
    description: 'Memory continuously increases when scrolling through large datasets in the grid component.',
    linkedFeatureId: 'f2', bugStatus: 'resolved', workflowStage: 'bug-tracking', priority: 'high',
    timeEstimate: 8, reportedDate: '2026-05-10',
    notes: 'Fixed by properly cleaning up event listeners on component unmount.',
    images: [
      'https://images.unsplash.com/photo-1551288049-bebda4e38f71?w=800',
      'https://images.unsplash.com/photo-1542831371-29b0f74f9713?w=800',
    ],
  },
  {
    id: 'b3', type: 'bug', title: 'Export fails for large datasets',
    description: 'CSV export times out when dataset exceeds 10,000 rows.',
    linkedFeatureId: 'f4', releaseId: 'r2', bugStatus: 'open', workflowStage: 'bug-tracking',
    priority: 'medium', timeEstimate: 6, reportedDate: '2026-05-25',
  },
];

export const sampleFeedback: Feedback[] = [
  {
    id: 'fb1', type: 'feedback', title: 'Add keyboard shortcuts',
    description: 'Power users want keyboard shortcuts for common actions like creating items, navigating between views, and quick search.',
    linkedFeatureId: 'f6', status: 'open', workflowStage: 'scoping', priority: 'medium',
    timeEstimate: 12, reportedDate: '2026-05-15', notes: '32 votes from users. High demand feature.',
  },
  {
    id: 'fb2', type: 'feedback', title: 'Improve mobile responsiveness',
    description: "Kanban board is hard to use on mobile devices. Cards are too small and drag-and-drop doesn't work well on touch screens.",
    linkedFeatureId: 'f7', linkedBugId: 'b1', status: 'in-progress', workflowStage: 'in-development',
    priority: 'high', timeEstimate: 16, reportedDate: '2026-05-05',
    urls: ['https://feedback.example.com/mobile-ux'],
  },
  {
    id: 'fb3', type: 'feedback', title: 'Dark mode color contrast needs improvement',
    description: 'Some text is hard to read in dark mode, particularly status badges and secondary text.',
    linkedFeatureId: 'f3', releaseId: 'r1', status: 'open', workflowStage: 'up-next',
    priority: 'low', timeEstimate: 4, reportedDate: '2026-05-22',
  },
];

export const sampleReleases: Release[] = [
  {
    id: 'r1', type: 'release', name: 'v2.0 - Major Release', quarter: 'Q2 2026',
    startWeek: '2026-05-01', endWeek: '2026-07-15', status: 'in-progress',
    totalTimeEstimate: 88, capacity: 80, isBigWedgeCampaign: true,
    linkedFeatureIds: ['f1', 'f2', 'f3'], linkedBugIds: ['b1'], linkedFeedbackIds: ['fb3'],
  },
  {
    id: 'r2', type: 'release', name: 'v2.1 - Feature Expansion', quarter: 'Q3 2026',
    startWeek: '2026-07-20', endWeek: '2026-09-30', status: 'planned',
    totalTimeEstimate: 58, capacity: 60, isBigWedgeCampaign: false,
    linkedFeatureIds: ['f4', 'f6'], linkedBugIds: ['b3'], linkedFeedbackIds: [],
  },
];

export const allItems: Item[] = [
  ...sampleFeatures,
  ...sampleSubItems,
  ...sampleBugs,
  ...sampleFeedback,
  ...sampleReleases,
];

export function getItemById( id: string ): Item | undefined {
  return allItems.find( ( item ) => item.id === id );
}

export function getReleaseName( releaseId?: string ): string | undefined {
  if ( ! releaseId ) return undefined;
  return sampleReleases.find( ( r ) => r.id === releaseId )?.name;
}
