import { X, Calendar, Clock, Link2, TrendingUp, Star, BarChart3, AlertCircle, Image as ImageIcon, ExternalLink, CheckCircle, Circle } from 'lucide-react';
import { useState, useEffect } from 'react';
import { Item, Feature, SubItem, Bug, Feedback, Release } from '../types';
import { ImageLightbox } from './ImageLightbox';
import { updateItem } from '../api/wordpress';
import { AppData } from '../App';

interface DetailModalProps {
  item: Item | null;
  data: AppData;
  isOpen: boolean;
  onClose: () => void;
  onUpdate?: () => void;
  isAdmin: boolean;
}

const TYPE_STYLES = {
  feature:  { bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200' },
  subitem:  { bg: 'bg-cyan-50',    text: 'text-cyan-700',    border: 'border-cyan-200' },
  bug:      { bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200' },
  feedback: { bg: 'bg-purple-50',  text: 'text-purple-700',  border: 'border-purple-200' },
  release:  { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
};

export function DetailModal( { item, data, isOpen, onClose, onUpdate, isAdmin }: DetailModalProps ) {
  const [lightboxOpen,  setLightboxOpen]  = useState( false );
  const [lightboxIndex, setLightboxIndex] = useState( 0 );
  const [isEditing,     setIsEditing]     = useState( false );
  const [isSaving,      setIsSaving]      = useState( false );
  const [editForm,      setEditForm]      = useState<any>( {} );

  useEffect( () => {
    if ( item ) { setEditForm( { ...item } ); setIsEditing( false ); }
  }, [item] );

  if ( ! isOpen || ! item ) return null;

  const typeStyle = TYPE_STYLES[item.type];

  const getItemById = ( id: string ) => data.allItems.find( ( i ) => i.id === id );
  const getReleaseName = ( rid?: string ) => rid ? data.releases.find( ( r ) => r.id === rid )?.name : undefined;

  const handleSave = async () => {
    setIsSaving( true );
    try {
      await updateItem( item.type, item.id, editForm );
      onUpdate?.();
      setIsEditing( false );
    } catch {
      // keep editing open on error
    } finally {
      setIsSaving( false );
    }
  };

  const timeField = ( key: string, label: string ) => (
    isEditing ? (
      <div className="flex items-center gap-1">
        <input type="number" value={ editForm[key] || 0 } onChange={ ( e ) => setEditForm( { ...editForm, [key]: parseInt( e.target.value ) || 0 } ) }
          className="w-20 text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
        <span className="text-sm font-medium text-muted-foreground">hours</span>
      </div>
    ) : <div className="text-sm font-semibold text-foreground">{ (item as any)[key] } hours</div>
  );

  const descField = ( key: string ) => (
    isEditing ? (
      <textarea value={ editForm[key] || '' } onChange={ ( e ) => setEditForm( { ...editForm, [key]: e.target.value } ) }
        className="w-full text-sm text-foreground p-3 bg-background border border-input rounded-md min-h-[120px] outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm resize-y" placeholder="Add a description..." />
    ) : <p className="text-sm text-foreground/90 leading-relaxed">{ (item as any)[key] }</p>
  );

  const imageGrid = ( images: string[] ) => (
    <>
      <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
        { images.map( ( url, index ) => (
          <button key={ index } onClick={ () => { setLightboxIndex( index ); setLightboxOpen( true ); } }
            className="group relative aspect-square rounded-lg overflow-hidden bg-muted border border-border hover:border-primary/40 transition-all hover:shadow-md">
            <img src={ url } alt={ `Attachment ${ index + 1 }` } className="w-full h-full object-cover transition-transform group-hover:scale-105" />
            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
              <ImageIcon className="w-6 h-6 text-white opacity-0 group-hover:opacity-100 transition-opacity drop-shadow" />
            </div>
          </button>
        ) ) }
      </div>
      <ImageLightbox images={ images } isOpen={ lightboxOpen } currentIndex={ lightboxIndex } onClose={ () => setLightboxOpen( false ) } onNavigate={ setLightboxIndex } />
    </>
  );

  const linkedBadge = ( colorBase: string, typeLabel: string, text: string ) => (
    <div className={ `flex items-center gap-3 p-3 bg-${ colorBase }-50/50 rounded-lg border border-${ colorBase }-200/50` }>
      <span className={ `px-2 py-0.5 text-xs font-semibold rounded border bg-${ colorBase }-50 text-${ colorBase }-700 border-${ colorBase }-200` }>{ typeLabel }</span>
      <span className="text-sm font-medium text-foreground flex-1">{ text }</span>
    </div>
  );

  const renderFeatureDetails = ( feature: Feature ) => {
    const release  = feature.releaseId ? getItemById( feature.releaseId ) as Release | undefined : undefined;
    const subItems = ( feature.subItemIds || [] ).map( ( id ) => getItemById( id ) ).filter( Boolean );
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-blue-600" /> } bg="bg-blue-100" label="Category" value={ feature.category } />
          <MetaCard icon={ <Star className="w-4 h-4 text-amber-600" /> } bg="bg-amber-100" label="Feature Price" value={ <span className="capitalize">{ feature.featurePrice }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate', 'Time Estimate' ) } />
          <MetaCard icon={ feature.isEnabled ? <CheckCircle className="w-4 h-4 text-green-600" /> : <Circle className="w-4 h-4 text-gray-400" /> } bg={ feature.isEnabled ? 'bg-green-100' : 'bg-gray-100' } label="Status" value={ feature.isEnabled ? 'Enabled' : 'Disabled' } />
          <MetaCard icon={ <BarChart3 className="w-4 h-4 text-indigo-600" /> } bg="bg-indigo-100" label="Stat Tracking" value={ feature.isTrackedAsStat ? 'Yes' : 'No' } />
          { release && <MetaCard icon={ <TrendingUp className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Release" value={ release.name } /> }
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
        { feature.images && feature.images.length > 0 && (
          <Section title={ <><ImageIcon className="w-4 h-4" /> Attachments ({ feature.images.length })</> }>{ imageGrid( feature.images ) }</Section>
        ) }
        { subItems.length > 0 && (
          <Section title={ `Sub-Items (${ subItems.length })` }>
            <div className="space-y-2">
              { subItems.map( ( si: any ) => (
                <div key={ si.id } className="flex items-center gap-3 p-3 bg-cyan-50/50 rounded-lg border border-cyan-200/50">
                  <span className="px-2 py-0.5 text-xs font-semibold rounded border bg-cyan-50 text-cyan-700 border-cyan-200">sub-item</span>
                  <span className="text-sm font-medium text-foreground flex-1">{ si.name }</span>
                  <span className="text-xs text-muted-foreground">{ si.timeEstimate }h</span>
                </div>
              ) ) }
            </div>
          </Section>
        ) }
      </>
    );
  };

  const renderSubItemDetails = ( subItem: SubItem ) => {
    const parent  = subItem.parentFeatureId ? getItemById( subItem.parentFeatureId ) as Feature | undefined : undefined;
    const release = subItem.releaseId ? getItemById( subItem.releaseId ) as Release | undefined : undefined;
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          { parent && <div className="col-span-full"><MetaCard icon={ <Link2 className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Parent Feature" value={ parent.name } /></div> }
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-cyan-600" /> } bg="bg-cyan-100" label="Category" value={ subItem.category } />
          <MetaCard icon={ <Star className="w-4 h-4 text-amber-600" /> } bg="bg-amber-100" label="Feature Price" value={ <span className="capitalize">{ subItem.featurePrice }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate', 'Time Estimate' ) } />
          { release && <MetaCard icon={ <TrendingUp className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Release" value={ release.name } /> }
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
      </>
    );
  };

  const renderBugDetails = ( bug: Bug ) => {
    const linkedFeature = bug.linkedFeatureId ? getItemById( bug.linkedFeatureId ) as Feature | undefined : undefined;
    const release = bug.releaseId ? getItemById( bug.releaseId ) as Release | undefined : undefined;
    const priBg = bug.priority === 'high' ? 'bg-orange-100' : bug.priority === 'medium' ? 'bg-yellow-100' : 'bg-slate-100';
    const priColor = bug.priority === 'high' ? 'bg-orange-600' : bug.priority === 'medium' ? 'bg-yellow-600' : 'bg-slate-600';
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <AlertCircle className="w-4 h-4 text-red-600" /> } bg="bg-red-100" label="Bug Status" value={ <span className="capitalize">{ bug.bugStatus }</span> } />
          <MetaCard icon={ <div className={ `w-2 h-2 rounded-full ${ priColor }` } /> } bg={ priBg } label="Priority" value={ <span className="capitalize">{ bug.priority }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate', 'Time Estimate' ) } />
          <MetaCard icon={ <Calendar className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Reported Date" value={ new Date( bug.reportedDate ).toLocaleDateString( 'en-US', { year: 'numeric', month: 'long', day: 'numeric' } ) } />
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
        { bug.notes && <Section title="Notes"><p className="text-sm text-muted-foreground leading-relaxed">{ bug.notes }</p></Section> }
        { bug.images && bug.images.length > 0 && <Section title={ <><ImageIcon className="w-4 h-4" /> Screenshots ({ bug.images.length })</> }>{ imageGrid( bug.images ) }</Section> }
        { bug.urls && bug.urls.length > 0 && (
          <Section title={ <><ExternalLink className="w-4 h-4" /> Related URLs</> }>
            <div className="space-y-2">
              { bug.urls.map( ( url, i ) => (
                <a key={ i } href={ url } target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 p-2.5 bg-muted/50 rounded-lg border border-border hover:border-primary/40 transition-colors text-sm text-primary hover:text-primary/80">
                  <ExternalLink className="w-3.5 h-3.5 flex-shrink-0" /><span className="truncate">{ url }</span>
                </a>
              ) ) }
            </div>
          </Section>
        ) }
        { ( linkedFeature || release ) && (
          <Section title={ <><Link2 className="w-4 h-4" /> Linked Items</> }>
            <div className="space-y-2">
              { linkedFeature && linkedBadge( 'blue', 'feature', linkedFeature.name ) }
              { release && linkedBadge( 'green', 'release', release.name ) }
            </div>
          </Section>
        ) }
      </>
    );
  };

  const renderFeedbackDetails = ( feedback: Feedback ) => {
    const linkedFeature = feedback.linkedFeatureId ? getItemById( feedback.linkedFeatureId ) as Feature | undefined : undefined;
    const linkedBug     = feedback.linkedBugId     ? getItemById( feedback.linkedBugId )     as Bug     | undefined : undefined;
    const release       = feedback.releaseId       ? getItemById( feedback.releaseId )       as Release | undefined : undefined;
    const priBg = feedback.priority === 'high' ? 'bg-orange-100' : feedback.priority === 'medium' ? 'bg-yellow-100' : 'bg-slate-100';
    const priColor = feedback.priority === 'high' ? 'bg-orange-600' : feedback.priority === 'medium' ? 'bg-yellow-600' : 'bg-slate-600';
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <MetaCard icon={ <div className="w-2 h-2 rounded-full bg-purple-600" /> } bg="bg-purple-100" label="Status" value={ <span className="capitalize">{ feedback.status }</span> } />
          <MetaCard icon={ <div className={ `w-2 h-2 rounded-full ${ priColor }` } /> } bg={ priBg } label="Priority" value={ <span className="capitalize">{ feedback.priority }</span> } />
          <MetaCard icon={ <Clock className="w-4 h-4 text-slate-600" /> } bg="bg-slate-100" label="Time Estimate" value={ timeField( 'timeEstimate', 'Time Estimate' ) } />
          <MetaCard icon={ <Calendar className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Reported Date" value={ new Date( feedback.reportedDate ).toLocaleDateString( 'en-US', { year: 'numeric', month: 'long', day: 'numeric' } ) } />
        </div>
        <Section title="Description">{ descField( 'description' ) }</Section>
        { feedback.notes && <Section title="Notes"><p className="text-sm text-muted-foreground leading-relaxed">{ feedback.notes }</p></Section> }
        { ( linkedFeature || linkedBug || release ) && (
          <Section title={ <><Link2 className="w-4 h-4" /> Linked Items</> }>
            <div className="space-y-2">
              { linkedFeature && linkedBadge( 'blue', 'feature', linkedFeature.name ) }
              { linkedBug     && linkedBadge( 'red',  'bug',     linkedBug.title ) }
              { release       && linkedBadge( 'green', 'release', release.name ) }
            </div>
          </Section>
        ) }
      </>
    );
  };

  const renderReleaseDetails = ( release: Release ) => {
    const isOverCapacity = release.totalTimeEstimate > release.capacity;
    const linkedFeatures = release.linkedFeatureIds.map( ( id ) => getItemById( id ) as Feature ).filter( Boolean );
    const linkedBugs     = release.linkedBugIds.map( ( id ) => getItemById( id ) as Bug ).filter( Boolean );
    const linkedFeedback = release.linkedFeedbackIds.map( ( id ) => getItemById( id ) as Feedback ).filter( Boolean );
    return (
      <>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <MetaCard icon={ <Calendar className="w-4 h-4 text-green-600" /> } bg="bg-green-100" label="Quarter" value={ release.quarter } />
          <MetaCard icon={ <TrendingUp className="w-4 h-4 text-blue-600" /> } bg="bg-blue-100" label="Status" value={ <span className="capitalize">{ release.status }</span> } />
          <div className="col-span-full flex items-start gap-3">
            <div className="w-8 h-8 p-2 rounded-lg bg-slate-100 flex-shrink-0"><Clock className="w-4 h-4 text-slate-600" /></div>
            <div className="flex-1">
              <div className="text-xs font-medium text-muted-foreground mb-0.5">Time &amp; Capacity</div>
              <div className="text-sm font-semibold text-foreground mb-2">
                { release.totalTimeEstimate } / { release.capacity } hours
                { isOverCapacity && <span className="ml-2 text-xs font-medium text-orange-600">({ release.totalTimeEstimate - release.capacity }h over)</span> }
              </div>
              <div className="w-full h-2 bg-muted rounded-full overflow-hidden">
                <div className={ `h-full transition-all ${ isOverCapacity ? 'bg-orange-500' : 'bg-green-500' }` } style={ { width: `${ Math.min( ( release.totalTimeEstimate / release.capacity ) * 100, 100 ) }%` } } />
              </div>
            </div>
          </div>
          { release.isBigWedgeCampaign && <MetaCard icon={ <Star className="w-4 h-4 text-violet-600" /> } bg="bg-violet-100" label="Campaign Type" value={ <span className="text-violet-700">Big Wedge Campaign</span> } colSpan /> }
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 p-2 rounded-lg bg-emerald-100 flex-shrink-0"><Calendar className="w-4 h-4 text-emerald-600" /></div>
            <div>
              <div className="text-xs font-medium text-muted-foreground mb-0.5">Start Week</div>
              { isEditing ? (
                <input type="date" value={ editForm.startWeek || '' } onChange={ ( e ) => setEditForm( { ...editForm, startWeek: e.target.value } ) }
                  className="text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
              ) : (
                <div className="text-sm font-semibold text-foreground">{ new Date( release.startWeek ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } ) }</div>
              ) }
            </div>
          </div>
          <div className="flex items-start gap-3">
            <div className="w-8 h-8 p-2 rounded-lg bg-rose-100 flex-shrink-0"><Calendar className="w-4 h-4 text-rose-600" /></div>
            <div>
              <div className="text-xs font-medium text-muted-foreground mb-0.5">End Week</div>
              { isEditing ? (
                <input type="date" value={ editForm.endWeek || '' } onChange={ ( e ) => setEditForm( { ...editForm, endWeek: e.target.value } ) }
                  className="text-sm font-semibold text-foreground bg-background border border-input rounded px-2 py-0.5 outline-none focus:border-primary focus:ring-1 focus:ring-primary shadow-sm" />
              ) : (
                <div className="text-sm font-semibold text-foreground">{ new Date( release.endWeek ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } ) }</div>
              ) }
            </div>
          </div>
        </div>
        { linkedFeatures.length > 0 && (
          <Section title={ `Linked Features (${ linkedFeatures.length })` }>
            <div className="space-y-2">{ linkedFeatures.map( ( f ) => <div key={ f.id } className="flex items-center gap-3 p-3 bg-blue-50/50 rounded-lg border border-blue-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-blue-50 text-blue-700 border-blue-200">feature</span><span className="text-sm font-medium text-foreground flex-1">{ f.name }</span><span className="text-xs text-muted-foreground">{ f.timeEstimate }h</span></div> ) }</div>
          </Section>
        ) }
        { linkedBugs.length > 0 && (
          <Section title={ `Linked Bugs (${ linkedBugs.length })` }>
            <div className="space-y-2">{ linkedBugs.map( ( b ) => <div key={ b.id } className="flex items-center gap-3 p-3 bg-red-50/50 rounded-lg border border-red-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-red-50 text-red-700 border-red-200">bug</span><span className="text-sm font-medium text-foreground flex-1">{ b.title }</span><span className="text-xs text-muted-foreground">{ b.timeEstimate }h</span></div> ) }</div>
          </Section>
        ) }
        { linkedFeedback.length > 0 && (
          <Section title={ `Linked Feedback (${ linkedFeedback.length })` }>
            <div className="space-y-2">{ linkedFeedback.map( ( f ) => <div key={ f.id } className="flex items-center gap-3 p-3 bg-purple-50/50 rounded-lg border border-purple-200/50"><span className="px-2 py-0.5 text-xs font-semibold rounded border bg-purple-50 text-purple-700 border-purple-200">feedback</span><span className="text-sm font-medium text-foreground flex-1">{ f.title }</span><span className="text-xs text-muted-foreground">{ f.timeEstimate }h</span></div> ) }</div>
          </Section>
        ) }
      </>
    );
  };

  const itemTitle = 'name' in item ? ( item as any ).name : ( item as any ).title;

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4 md:p-6 lg:p-8">
      <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={ onClose } />
      <div className="relative bg-background rounded-t-xl sm:rounded-xl shadow-2xl w-full max-w-3xl max-h-[95vh] sm:max-h-[90vh] overflow-hidden flex flex-col border border-border">
        {/* Header */}
        <div className="flex items-start justify-between p-5 sm:p-6 border-b border-border bg-gradient-to-b from-muted/30 to-background">
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2 mb-3 flex-wrap">
              <span className={ `px-2.5 py-1 text-xs font-semibold rounded border ${ typeStyle.bg } ${ typeStyle.text } ${ typeStyle.border }` }>{ item.type }</span>
              { 'workflowStage' in item && (
                <span className="px-2.5 py-1 text-xs font-medium rounded border bg-slate-100 text-slate-700 border-slate-300">
                  { item.workflowStage.split( '-' ).map( ( w ) => w.charAt( 0 ).toUpperCase() + w.slice( 1 ) ).join( ' ' ) }
                </span>
              ) }
            </div>
            { isEditing ? (
              <input autoFocus value={ editForm.name || editForm.title || '' }
                onChange={ ( e ) => {
                  const key = 'name' in editForm ? 'name' : 'title';
                  setEditForm( { ...editForm, [key]: e.target.value } );
                } }
                className="text-lg sm:text-xl font-bold text-foreground bg-background border border-primary/50 outline-none focus:ring-1 focus:ring-primary px-2 py-1 w-full rounded-md shadow-sm" />
            ) : (
              <h2 className="text-lg sm:text-xl font-bold text-foreground">{ itemTitle }</h2>
            ) }
          </div>
          <button onClick={ onClose } className="p-2 hover:bg-accent rounded-lg transition-colors flex-shrink-0">
            <X className="w-5 h-5 text-muted-foreground" />
          </button>
        </div>

        {/* Content */}
        <div className="flex-1 overflow-y-auto p-5 sm:p-6 space-y-6">
          { item.type === 'feature'  && renderFeatureDetails( item as Feature ) }
          { item.type === 'subitem'  && renderSubItemDetails( item as SubItem ) }
          { item.type === 'bug'      && renderBugDetails( item as Bug ) }
          { item.type === 'feedback' && renderFeedbackDetails( item as Feedback ) }
          { item.type === 'release'  && renderReleaseDetails( item as Release ) }
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 p-5 sm:p-6 border-t border-border bg-muted/20">
          { isEditing ? (
            <>
              <button onClick={ () => { setIsEditing( false ); setEditForm( { ...item } ); } } className="px-4 py-2 text-sm font-medium text-foreground hover:bg-accent rounded-lg transition-colors">Cancel</button>
              <button onClick={ handleSave } disabled={ isSaving } className="px-4 py-2 text-sm font-medium bg-green-600 text-white hover:bg-green-700 rounded-lg transition-colors flex items-center gap-2 shadow-sm disabled:opacity-60">
                <CheckCircle className="w-4 h-4" />{ isSaving ? 'Saving...' : 'Save Changes' }
              </button>
            </>
          ) : (
            <>
              <button onClick={ onClose } className="px-4 py-2 text-sm font-medium text-foreground hover:bg-accent rounded-lg transition-colors">Close</button>
              { isAdmin && (
                <button onClick={ () => setIsEditing( true ) } className="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm rounded-lg transition-colors">
                  Edit { item.type === 'feature' ? 'Feature' : item.type === 'subitem' ? 'Sub-Item' : item.type === 'bug' ? 'Bug' : item.type === 'feedback' ? 'Feedback' : 'Release' }
                </button>
              ) }
            </>
          ) }
        </div>
      </div>
    </div>
  );
}

function MetaCard( { icon, bg, label, value, colSpan }: { icon: React.ReactNode; bg: string; label: string; value: React.ReactNode; colSpan?: boolean } ) {
  return (
    <div className={ `flex items-start gap-3 ${ colSpan ? 'col-span-full' : '' }` }>
      <div className={ `p-2 rounded-lg ${ bg } flex items-center justify-center` }>{ icon }</div>
      <div><div className="text-xs font-medium text-muted-foreground mb-0.5">{ label }</div><div className="text-sm font-semibold text-foreground">{ value }</div></div>
    </div>
  );
}

function Section( { title, children }: { title: React.ReactNode; children: React.ReactNode } ) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-foreground mb-2.5 flex items-center gap-2">{ title }</h3>
      { children }
    </div>
  );
}
