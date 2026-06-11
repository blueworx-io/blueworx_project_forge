import { GripVertical, Calendar, Clock, Link2, TrendingUp, Star, BarChart3, AlertCircle, AlertTriangle } from 'lucide-react';
import { Item, Feature, SubItem, Bug, Feedback, Release } from '../types';
import { useShallow } from 'zustand/react/shallow';
import { useDataStore, selectAllItems } from '../store/useDataStore';
import { getDependencyIds, hasUnresolvedBlockers } from '../utils/dependencies';
import { formatDate } from '../utils/dates';

interface ItemCardProps {
  item: Item;
  onClick: () => void;
  showDragHandle?: boolean;
}

const TYPE_STYLES = {
  feature:  { bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200',    accent: 'bg-blue-500',    borderLeft: 'border-l-blue-500' },
  subitem:  { bg: 'bg-cyan-50',    text: 'text-cyan-700',    border: 'border-cyan-200',    accent: 'bg-cyan-500',    borderLeft: 'border-l-cyan-500' },
  bug:      { bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',     accent: 'bg-red-500',     borderLeft: 'border-l-red-500' },
  feedback: { bg: 'bg-purple-50',  text: 'text-purple-700',  border: 'border-purple-200',  accent: 'bg-purple-500',  borderLeft: 'border-l-purple-500' },
  release:  { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', accent: 'bg-emerald-500', borderLeft: 'border-l-emerald-500' },
};

const FEATURE_PRICE_STYLES = {
  scoping: 'bg-gray-100 text-gray-700 border-gray-300',
  premium: 'bg-amber-100 text-amber-700 border-amber-300',
  teaser:  'bg-indigo-100 text-indigo-700 border-indigo-300',
  free:    'bg-emerald-100 text-emerald-700 border-emerald-300',
};

const PRIORITY_STYLES = {
  high:   'bg-orange-100 text-orange-700 border-orange-300',
  medium: 'bg-yellow-100 text-yellow-700 border-yellow-300',
  low:    'bg-slate-100 text-slate-700 border-slate-300',
};

export function ItemCard( { item, onClick, showDragHandle = false }: ItemCardProps ) {
  const releases = useDataStore( s => s.releases );
  const allItems = useDataStore( useShallow( selectAllItems ) );
  const depCount = getDependencyIds( item ).length;
  const blocked  = hasUnresolvedBlockers( item, allItems );
  const typeStyle = TYPE_STYLES[item.type];
  const releaseName = 'releaseId' in item && item.releaseId
    ? releases.find( ( r ) => r.id === item.releaseId )?.name
    : undefined;

  const renderFeatureCard = ( feature: Feature ) => (
    <>
      <div className="flex items-center gap-2 mb-2 flex-wrap">
        <span className={ `px-2 py-0.5 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>Feature</span>
        <span className={ `px-2 py-0.5 text-xs font-medium rounded border ${ FEATURE_PRICE_STYLES[feature.featurePrice] }` }>{ feature.featurePrice }</span>
        { feature.isEnabled && <span className="px-2 py-0.5 text-xs font-medium rounded border bg-green-100 text-green-700 border-green-300">Enabled</span> }
        { feature.isTrackedAsStat && <span title="Tracked as stat" className="flex"><BarChart3 className="w-3.5 h-3.5 text-blue-600" /></span> }
      </div>
      <h4 className="text-sm font-semibold text-foreground mb-2 line-clamp-2">{ feature.name }</h4>
      <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground mb-2">
        <div className="flex items-center gap-1"><div className={ `w-2 h-2 rounded-full ${ typeStyle.accent }` } /><span className="font-medium">{ feature.category }</span></div>
        <div className="flex items-center gap-1"><Clock className="w-3 h-3" /><span>{ feature.timeEstimate }h</span></div>
        { releaseName && <div className="flex items-center gap-1"><TrendingUp className="w-3 h-3" /><span>{ releaseName }</span></div> }
      </div>
      { feature.subItemIds && feature.subItemIds.length > 0 && (
        <div className="mt-2 pt-2 border-t border-border">
          <span className="text-xs text-muted-foreground">{ feature.subItemIds.length } sub-item{ feature.subItemIds.length !== 1 ? 's' : '' }</span>
        </div>
      ) }
    </>
  );

  const renderSubItemCard = ( subItem: SubItem ) => (
    <>
      <div className="flex items-center gap-2 mb-2 flex-wrap">
        <span className={ `px-2 py-0.5 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>Sub-Item</span>
        <span className={ `px-2 py-0.5 text-xs font-medium rounded border ${ FEATURE_PRICE_STYLES[subItem.featurePrice] }` }>{ subItem.featurePrice }</span>
      </div>
      <h4 className="text-sm font-semibold text-foreground mb-2 line-clamp-2">{ subItem.name }</h4>
      <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
        <div className="flex items-center gap-1"><Link2 className="w-3 h-3" /><span>Parent feature</span></div>
        <div className="flex items-center gap-1"><Clock className="w-3 h-3" /><span>{ subItem.timeEstimate }h</span></div>
      </div>
    </>
  );

  const renderBugCard = ( bug: Bug ) => (
    <>
      <div className="flex items-center gap-2 mb-2 flex-wrap">
        <span className={ `px-2 py-0.5 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>Bug</span>
        <span className={ `px-2 py-0.5 text-xs font-medium rounded border ${ PRIORITY_STYLES[bug.priority] }` }>{ bug.priority }</span>
        <span className="px-2 py-0.5 text-xs font-medium rounded border bg-slate-100 text-slate-700 border-slate-300">{ bug.bugStatus }</span>
      </div>
      <h4 className="text-sm font-semibold text-foreground mb-2 line-clamp-2">{ bug.title }</h4>
      <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
        <div className="flex items-center gap-1"><Clock className="w-3 h-3" /><span>{ bug.timeEstimate }h</span></div>
        <div className="flex items-center gap-1"><Calendar className="w-3 h-3" /><span>{ formatDate( bug.reportedDate ) }</span></div>
        { bug.linkedFeatureId && <div className="flex items-center gap-1"><Link2 className="w-3 h-3" /><span>Linked</span></div> }
      </div>
    </>
  );

  const renderFeedbackCard = ( feedback: Feedback ) => (
    <>
      <div className="flex items-center gap-2 mb-2 flex-wrap">
        <span className={ `px-2 py-0.5 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>Feedback</span>
        <span className={ `px-2 py-0.5 text-xs font-medium rounded border ${ PRIORITY_STYLES[feedback.priority] }` }>{ feedback.priority }</span>
        <span className="px-2 py-0.5 text-xs font-medium rounded border bg-slate-100 text-slate-700 border-slate-300">{ feedback.status }</span>
      </div>
      <h4 className="text-sm font-semibold text-foreground mb-2 line-clamp-2">{ feedback.title }</h4>
      <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
        <div className="flex items-center gap-1"><Clock className="w-3 h-3" /><span>{ feedback.timeEstimate }h</span></div>
        <div className="flex items-center gap-1"><Calendar className="w-3 h-3" /><span>{ formatDate( feedback.reportedDate ) }</span></div>
        { ( feedback.linkedFeatureId || feedback.linkedBugId ) && <div className="flex items-center gap-1"><Link2 className="w-3 h-3" /><span>Linked</span></div> }
      </div>
    </>
  );

  const renderReleaseCard = ( release: Release ) => {
    const isOverCapacity = release.totalTimeEstimate > release.capacity;
    const itemCount = release.linkedFeatureIds.length + release.linkedBugIds.length + release.linkedFeedbackIds.length;
    return (
      <>
        <div className="flex items-center gap-2 mb-2 flex-wrap">
          <span className={ `px-2 py-0.5 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>Release</span>
          { release.isBigWedgeCampaign && (
            <span className="px-2 py-0.5 text-xs font-semibold rounded border bg-violet-100 text-violet-700 border-violet-300 flex items-center gap-1">
              <Star className="w-3 h-3" />Big Wedge Campaign
            </span>
          ) }
          { isOverCapacity && (
            <span className="px-2 py-0.5 text-xs font-semibold rounded border bg-orange-100 text-orange-700 border-orange-300 flex items-center gap-1">
              <AlertCircle className="w-3 h-3" />Over Capacity
            </span>
          ) }
        </div>
        <h4 className="text-sm font-semibold text-foreground mb-2">{ release.name }</h4>
        <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground mb-3">
          <div className="flex items-center gap-1"><Calendar className="w-3 h-3" /><span>{ release.quarter }</span></div>
          <div className="flex items-center gap-1"><Clock className="w-3 h-3" /><span>{ release.totalTimeEstimate }h / { release.capacity }h</span></div>
        </div>
        <div className="w-full h-1.5 bg-muted rounded-full overflow-hidden mb-2">
          <div className={ `h-full transition-all ${ isOverCapacity ? 'bg-orange-500' : 'bg-green-500' }` }
            style={ { width: `${ Math.min( ( release.totalTimeEstimate / release.capacity ) * 100, 100 ) }%` } }
          />
        </div>
        { itemCount > 0 && (
          <div className="mt-2 pt-2 border-t border-border">
            <span className="text-xs text-muted-foreground">{ itemCount } linked item{ itemCount !== 1 ? 's' : '' }</span>
          </div>
        ) }
      </>
    );
  };

  return (
    <div onClick={ onClick } style={{ touchAction: 'manipulation' }} className={ `group bg-card border-l-4 border-r border-t border-b rounded-lg p-3.5 hover:shadow-lg hover:shadow-black/5 transition-all cursor-pointer ${ typeStyle.borderLeft }` }>
      <div className="flex items-start gap-2.5">
        { showDragHandle && (
          <div className="flex-shrink-0 mt-0.5 opacity-0 group-hover:opacity-100 transition-opacity cursor-grab">
            <GripVertical className="w-4 h-4 text-muted-foreground" />
          </div>
        ) }
        <div className="flex-1 min-w-0">
          { item.type === 'feature'  && renderFeatureCard( item as Feature ) }
          { item.type === 'subitem'  && renderSubItemCard( item as SubItem ) }
          { item.type === 'bug'      && renderBugCard( item as Bug ) }
          { item.type === 'feedback' && renderFeedbackCard( item as Feedback ) }
          { item.type === 'release'  && renderReleaseCard( item as Release ) }
          { ( depCount > 0 || blocked ) && (
            <div className="flex items-center gap-2 mt-2 pt-2 border-t border-border">
              { depCount > 0 && (
                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                  <Link2 className="w-3 h-3" />{ depCount } dep{ depCount !== 1 ? 's' : '' }
                </span>
              ) }
              { blocked && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded border bg-red-100 text-red-700 border-red-300">
                  <AlertTriangle className="w-3 h-3" />Blocked
                </span>
              ) }
            </div>
          ) }
        </div>
      </div>
    </div>
  );
}
