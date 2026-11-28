<?php
/** Model-only read handles expanded into the unchanged native inspection. */
namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Builder\{BuilderEditorSelection,GraphVersion};
use DirectoristAIPro\Validation\{ContractValidator,JsonCanonicalizer,JsonDecoder,SchemaRegistry};
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** Handles remove copied hashes; the gateway still owns all permission/version checks. */
final class BuilderReadTargetTransport {
	public const FUNCTION_NAME = 'directorist_ai_inspect_area';
	public const NATIVE_FUNCTION = 'wpab__directorist-ai__builder-inspect';
	public const ABILITY_NAME = 'directorist-ai/builder-inspect';
	private const REVISION = 'builder-read-targets-v1';

	/** Conservative opt-in: mixed/query goals retain ordinary discovery unchanged. */
	public static function control_only_goal( string $text ): bool {
		$text = \DirectoristAIPro\Builder\BuilderStyleIntent::user_text( $text );
		$text = preg_replace_callback( '/(?:^|(?<=[.!?;]))\s*(?:keep|preserve|leave)\b[^.!?;]*(?=[.!?;]|$)/ui', static function ( array $match ): string {
			return preg_match( '/\b(?:but|then|change|switch|filter|include|show|hide|add|insert|create|move|replace|remove|delete|reorder|sort)\b/i', $match[0] ) ? $match[0] : ' ';
		}, $text );
		if ( preg_match( '/\b(?:quer(?:y|ies)|director(?:y|ies)|categories|category\s+filter|search\s+(?:term|query)|results?\s+(?:filter|sort|order))\b/i', $text ) ) { return false; }
		return ! preg_match( '/\b(?:include|enable|introduce)\b[^.!?;]{0,80}\b(?:section|row|column|widget|module|block|search|filter|listing|loop|map|component|field|gallery|carousel)s?\b/i', $text );
	}

	/** Capture only nodes already indexed in the current model view of a native receipt. */
	public static function capture( array $entries, array $projected, object $binding, object $authority ): ?object {
		$c=new JsonCanonicalizer();$source=null;$base=null;$visible_ids=array();$proofs=array();
		if(!($binding->builder_document??null)instanceof \stdClass||!($binding->builder_scope??null)instanceof \stdClass)return null;
		foreach(array_reverse($entries)as$entry){
			$d=$entry->response->data??null;$g=$d->graph??null;$i=is_string($entry->input_json??null)?json_decode($entry->input_json):null;
			if(true!==($entry->response->ok??null)||'builder.inspect'!==($entry->response->operation??null)||!$g instanceof \stdClass||!$i instanceof \stdClass||'complete'!==($g->completeness??null)||'complete'!==($g->consumer_coverage??null)||array()!==($g->omissions??null)||true!==($d->semantic_report->accepted??null)||'current'!==($d->projection->status??null)||!is_array($g->nodes??null)||!is_array($g->edges??null)||count($g->nodes)>1024||count($g->edges)>4096)continue;
			try{if($c->digest($g)!==($d->graph_hash??null)||(new GraphVersion((array)$g->version))->hash()!==($d->semantic_report->graph_version_hash??null)||($d->fact_revision??null)!==($d->semantic_report->fact_revision??null)||$c->encode($g->document)!==$c->encode($binding->builder_document??null)||$c->encode($i->document??null)!==$c->encode($g->document)||$c->encode($i->scope??null)!==$c->encode($binding->builder_scope??null))continue;}catch(\Throwable $error){continue;}
			$source=$d;$base=$i;break;
		}
		if(null===$source)return null;
		foreach($projected as$view){$d=$view->response->data??null;if(true!==($view->response->ok??null)||'builder.inspect'!==($view->response->operation??null)||($d->graph_hash??null)!==$source->graph_hash||($d->fact_revision??null)!==$source->fact_revision||!is_array($d->graph->nodes??null))continue;foreach($d->graph->nodes as$node)if(is_string($node->id??null))$visible_ids[$node->id]=true;}
		if(!$visible_ids)return null;
		$g=$source->graph;$scope=$binding->builder_scope;$nodes=array();$children=array();$allowed=array();
		foreach($g->nodes as$node){if(!$node instanceof \stdClass||!is_string($node->id??null)||isset($nodes[$node->id])||!($node->locator??null)instanceof \stdClass||$c->encode($node->locator->document??null)!==$c->encode($g->document))return null;$nodes[$node->id]=$node;}
		foreach($g->edges as$edge){if('contains'===($edge->kind??null)&&null===($edge->target_document_key??null)&&isset($nodes[$edge->source??''],$nodes[$edge->target??'']))$children[$edge->source][$edge->target]=true;}
		// A public-looking descendant cannot escape a private or opaque ancestor.
		$denied=array();$queue=array();foreach($nodes as$id=>$node)if(null!==($node->opaque_ref??null)||'native/opaque'===($node->provider_id??null)||!self::public_entity($node)||!self::public_entity($node->attributes??null))$queue[]=$id;
		for($at=0;$at<count($queue);++$at){$id=$queue[$at];if(isset($denied[$id]))continue;$denied[$id]=true;foreach(array_keys($children[$id]??array())as$child)$queue[]=$child;}
		if(isset($binding->builder_selection)){
			try{$selected=BuilderEditorSelection::resolve_node($binding->builder_selection,$g);}catch(\Throwable $error){return null;}
			$queue=array($selected->id);for($at=0;$at<count($queue);++$at){$id=$queue[$at];if(isset($allowed[$id]))continue;$allowed[$id]=true;foreach(array_keys($children[$id]??array())as$child)$queue[]=$child;}
		}else{foreach($nodes as$id=>$node)$allowed[$id]=true;}
		foreach($entries as$entry){$proof=BuilderControlHandoff::visible($entry,$projected,$binding->builder_selection??null);if(null!==$proof&&$c->encode($proof->source->document)===$c->encode($g->document)&&$proof->source->graph_hash===$source->graph_hash&&$proof->source->fact_revision===$source->fact_revision){$proofs[$proof->node_id]=array_values(array_unique(array_merge($proofs[$proof->node_id]??array(),$proof->paths)));}}
		$targets=array();$public=array();$counter=0;
		foreach($nodes as$id=>$node){
			if(!isset($visible_ids[$id],$allowed[$id])||isset($denied[$id])||$c->encode($node->scope??null)!==$c->encode($scope))continue;
			$root='directorist-ai'===($node->provider_id??null)&&($g->document->builder.'-document')===($node->native_id??null);
			if(isset($binding->builder_selection)&&$root)continue;
			$key=$root?'document':'area_'.(++$counter);$done=$proofs[$id]??array();
			$targets[$key]=(object)array('node_id'=>$id,'provider_id'=>$node->provider_id,'native_id'=>$node->native_id,'locator'=>$node->locator,'selection'=>$root?array():array($node->locator),'completed_paths'=>$done);
			$public[]=(object)array('target'=>$key,'node_id'=>$id,'native_id'=>$node->native_id,'already_inspected_control_paths'=>$done);
			if(count($targets)>=24)break;
		}
		if(!$targets)return null;
		// Reuse the privacy-checked, bounded native heading orientation for Divi containers.
		if('divi'===$g->document->builder){
			$orientation=BuilderDocumentDiscoveryPrompt::from_graph($g,array_column(array_values($targets),'node_id'),$scope,array('divi/section','divi/row','divi/column'));
			foreach($public as$item)foreach($orientation->containers??array()as$container)if($container->node_id===$item->node_id){$item->heading_samples=$container->heading_samples;$item->descendant_nodes=$container->descendant_nodes;}
		}
		$snapshot=(object)array('revision'=>self::REVISION,'authority'=>$authority,'base_input'=>(object)array('document'=>$g->document,'directory_id'=>$base->directory_id??null,'scope'=>$scope,'editor_binding_id'=>$base->editor_binding_id??null),'source'=>(object)array('document_key'=>$source->document_key??null,'graph_hash'=>$source->graph_hash,'opaque_hash'=>$source->opaque_hash??null,'fact_revision'=>$source->fact_revision,'graph_version_hash'=>$source->semantic_report->graph_version_hash,'rule_set_revision'=>$source->semantic_report->rule_set_revision,'catalog_revision'=>$source->authoring->catalog_revision??null),'targets'=>(object)$targets,'public_targets'=>$public,'control_indexes'=>self::complete_indexes($entries,$source,$targets));
		if ( 'gutenberg' === $g->document->builder ) {
			$snapshot->omit_provider_conditions = true;
		}
		// Reserve the final admitted schema/hash framing, not merely the raw map.
		while(true){$probe=clone$snapshot;$probe->native_schema_hash=str_repeat('a',64);$probe->parameters=self::parameters($snapshot);$probe->snapshot_hash=str_repeat('a',64);$probe->parameters->{'$comment'}=self::REVISION.':'.str_repeat('a',64);try{(new JsonDecoder(32768,32))->check($probe);break;}catch(\Throwable $error){if(!$snapshot->control_indexes)return null;array_pop($snapshot->control_indexes);}}
		return $snapshot;
	}

	/** Bind a frozen private map to the exact native ability contract and current owner. */
	public static function prepare( $snapshot, string $authority_hash, array $native_envelope ): object {
		if(is_string($snapshot))$snapshot=(new JsonDecoder(32768,32))->decode($snapshot);
		if(!$snapshot instanceof \stdClass||self::REVISION!==($snapshot->revision??null)||!($snapshot->authority??null)instanceof \stdClass||!($snapshot->targets??null)instanceof \stdClass||!get_object_vars($snapshot->targets)||count(get_object_vars($snapshot->targets))>24||self::hash($snapshot->authority)!==$authority_hash)throw new \UnexpectedValueException('The current native read handles are unavailable.');
		if(isset($snapshot->snapshot_hash)||isset($snapshot->parameters)){
			if(!is_string($snapshot->snapshot_hash??null)||!($snapshot->parameters??null)instanceof \stdClass||$snapshot->snapshot_hash!==self::snapshot_hash($snapshot)||($snapshot->parameters->{'$comment'}??null)!==self::REVISION.':'.$snapshot->snapshot_hash)throw new \UnexpectedValueException('The frozen native read map changed.');
		}
		$native_hash=self::hash(json_decode(self::json($native_envelope)));
		if(isset($snapshot->native_schema_hash)&&$snapshot->native_schema_hash!==$native_hash)throw new \UnexpectedValueException('The native read contract changed.');
		$snapshot=json_decode(self::json($snapshot));$snapshot->native_schema_hash=$native_hash;$snapshot->parameters=self::parameters($snapshot);
		$snapshot->snapshot_hash=self::snapshot_hash($snapshot);$snapshot->parameters->{'$comment'}=self::REVISION.':'.$snapshot->snapshot_hash;
		(new JsonDecoder(32768,32))->check($snapshot);return$snapshot;
	}

	/**
	 * Publish the prepared native read declaration.
	 *
	 * @param object $snapshot Current native read snapshot.
	 * @return FunctionDeclaration Provider tool declaration.
	 */
	public static function declaration( object $snapshot ): FunctionDeclaration {
		return new FunctionDeclaration( self::FUNCTION_NAME, 'Inspect a current native area using direct target/include_authoring/control_paths/authoring_components arguments, not input_json. Do not copy document/scope/hashes. control_paths are exact REGISTERED control/group identifiers, not guessed nested property leaves. Where choices are declared use those exact identifiers. Already-inspected groups retain complete writable schemas and known current values, including known {} overrides: use them to propose requested leaf changes with builder.draft_propose instead of rereading or requesting those leaves as control paths. Inspect only genuinely missing groups, areas or new components. Native authorization and stale-source checks still apply.', self::declaration_parameters( $snapshot ) );
	}

	/**
	 * OpenAI requires a plain root object. Keep the frozen conditional validation locally.
	 *
	 * @param object $snapshot Prepared native read declaration.
	 * @return array Provider-visible parameters.
	 */
	private static function declaration_parameters( object $snapshot ): array {
		$parameters = (array) $snapshot->parameters;
		if ( true === ( $snapshot->omit_provider_conditions ?? false ) ) {
			unset( $parameters['allOf'] );
		}
		return $parameters;
	}

	/** Supply only public labels; private locators/owner binding never enter task data. */
	public static function prompt(string $prompt,object $snapshot):string {
		$start="BEGIN_UNTRUSTED_DATA\n";$end="\nEND_UNTRUSTED_DATA";
		if(!str_starts_with($prompt,$start)||!str_ends_with($prompt,$end))throw new \UnexpectedValueException('The builder read packet changed.');
		$packet=(new JsonDecoder(262144,48))->decode(substr($prompt,strlen($start),-strlen($end)));
		if(!$packet instanceof \stdClass||'builder'!==($packet->profile??null))throw new \UnexpectedValueException('The builder read profile changed.');
		$packet->builder_read_targets=(object)array('targets'=>$snapshot->public_targets,'partial'=>true,'editable_values'=>false,'note'=>'Current read-only area labels. Choose the intended native area by target; the server supplies its exact original locator. Already-inspected paths retain their complete current schemas/values in the packet. Other groups and indexed areas remain readable. This grants no proposal or approval authority.');
		return$start.self::json($packet).$end;
	}

	/** Acknowledge only schemas/values proved retained in this current native map. */
	public static function ready_instruction( ?object $snapshot ): string {
		if ( null === $snapshot || ! ( $snapshot->targets ?? null ) instanceof \stdClass ) { return ''; }
		$ready = false;
		foreach ( $snapshot->targets as $target ) { if ( ! empty( $target->completed_paths ) ) { $ready = true; break; } }
		if ( ! $ready ) { return ''; }
		return ' Current native authoring handoff: exact groups listed as already_inspected_control_paths in builder_read_targets already have complete writable schemas and KNOWN current values retained in fresh_tool_results. Inspection of those groups is finished. Match the user\'s intended region to saved heading/role hints FIRST. Readiness does not identify the intended target: never substitute a different ready area for an intended area whose contract is still missing. Once that intended target is ready, prepare its supported set_control changes with the declared proposal handoff. Known {} means no saved family overrides, not missing information. Choose requested responsive leaves from the complete group schema; do not request guessed nested property leaves as control_paths or reread unchanged complete groups. Preserve all unrelated saved family members and native controls. If an essential different group, area or component is genuinely missing, inspect only that missing contract; those reads remain available. Do not repeat a complete builder.describe catalog already retained for the same revision. This handoff grants no new permissions, approval or saved effect; exact current native validation remains required.';
	}

	/** Diagnose one rejected batch from admitted PUBLIC identifiers, never raw text. */
	public static function validation_feedback( ModelRequest $request, string $raw ): ?object {
		try {
			$s = self::for_request( $request );
			$a = ( new JsonDecoder( 65536, 32 ) )->decode( $raw );
			if ( null === $s || ! $a instanceof \stdClass || ! is_string( $a->target ?? null ) || ! ( $s->targets->{$a->target} ?? null ) instanceof \stdClass ) { return null; }
			$t = $s->targets->{$a->target}; $requested = is_array( $a->control_paths ?? null ) ? $a->control_paths : array();
			$completed = array_values( array_intersect( $t->completed_paths, array_filter( $requested, 'is_string' ) ) );
			$choices = array();
			foreach ( $s->control_indexes ?? array() as $index ) { if ( $index->provider_id === $t->provider_id && $index->native_id === $t->native_id ) { $choices = $index->paths; break; } }
			usort( $choices, static fn( string $x, string $y ): int => (int) (bool) preg_match( '/^native_controls\..*(?:spacing|sizing)$/i', $y ) <=> (int) (bool) preg_match( '/^native_controls\..*(?:spacing|sizing)$/i', $x ) );
			$reason = $completed ? 'already_complete' : ( $choices && array_diff( array_filter( $requested, 'is_string' ), $choices ) ? 'unregistered_control' : 'read_contract_invalid' );
			$feedback = (object) array( 'version'=>1, 'authority_hash'=>self::hash($s->authority), 'map_hash'=>self::map_hash($s), 'source'=>$s->source, 'batch_not_executed'=>true, 'reason'=>$reason, 'target'=>$a->target, 'node_id'=>$t->node_id, 'provider_id'=>$t->provider_id, 'native_id'=>$t->native_id, 'completed_paths'=>array_slice($completed,0,16), 'completed_path_count'=>count($completed), 'declared_choices'=>array_slice($choices,0,16), 'declared_choice_count'=>count($choices), 'choices_partial'=>count($choices)>16 );
			while ( strlen( self::json( $feedback ) ) > 4096 && $feedback->declared_choices ) { array_pop( $feedback->declared_choices ); $feedback->choices_partial = true; }
			( new JsonDecoder( 4096, 16 ) )->check( $feedback );
			return $feedback;
		} catch ( \Throwable $error ) { return null; }
	}

	/** Exact current admission/capture/source binding precedes private checkpointing. */
	public static function bind_feedback( ?object $feedback, ModelRequest $request, ?object $authority ): ?object {
		try {
			$s = self::for_request( $request );
			if ( null === $s || null === $authority || self::hash($authority)!==$request->builder_read_authority_hash || ''===self::repair_instruction($feedback,$s) ) { return null; }
			return json_decode( self::json($feedback) );
		} catch ( \Throwable $error ) { return null; }
	}

	/** New turns, captures, scopes, revisions or target maps discard earlier hints. */
	public static function repair_instruction( ?object $feedback, ?object $snapshot ): string {
		try {
			if ( null === $feedback || null === $snapshot || 1!==($feedback->version??null) || true!==($feedback->batch_not_executed??null) || ($feedback->authority_hash??null)!==self::hash($snapshot->authority) || ($feedback->map_hash??null)!==self::map_hash($snapshot) || self::hash($feedback->source??null)!==self::hash($snapshot->source) || !in_array($feedback->reason??null,array('already_complete','unregistered_control','read_contract_invalid'),true) || !is_string($feedback->target??null) || !($snapshot->targets->{$feedback->target}??null)instanceof \stdClass ) { return ''; }
			$t=$snapshot->targets->{$feedback->target};
			if(($feedback->node_id??null)!==$t->node_id||($feedback->provider_id??null)!==$t->provider_id||($feedback->native_id??null)!==$t->native_id||!is_array($feedback->completed_paths??null)||array_diff($feedback->completed_paths,$t->completed_paths))return '';
			$allowed=array();foreach($snapshot->control_indexes??array()as$i)if($i->provider_id===$t->provider_id&&$i->native_id===$t->native_id)$allowed=$i->paths;
			if(!is_array($feedback->declared_choices??null)||array_diff($feedback->declared_choices,$allowed))return '';
			( new JsonDecoder(4096,16) )->check($feedback);
			$public=(object)array('target'=>$feedback->target,'reason'=>$feedback->reason,'already_complete_paths'=>$feedback->completed_paths,'registered_choices'=>$feedback->declared_choices,'choices_partial'=>true===($feedback->choices_partial??null));
			return ' Native read repair: the previous WHOLE returned tool batch was rejected BEFORE execution. No other read or action in that batch ran, even if individually valid. Do not assume its requested Section/Row inspection succeeded. The server-confirmed public diagnosis is '.self::json($public).'. Already-complete groups retain their exact writable schemas and known current values: propose supported requested edits using them. For a genuinely uninspected area/group, send only that missing valid read; never batch it with completed rereads. Choose exact registered group identifiers from the declaration, not guessed nested property paths. Do not repeat the same complete catalog description. This is advisory repair information, not permission or validation authority.';
		} catch ( \Throwable $error ) { return ''; }
	}

	/** Accept only the immutable admitted declaration, including pending-result replay. */
	public static function for_request( ModelRequest $request ): ?object {
		$s = $request->builder_read_targets;
		if ( null === $s ) {
			return null;
		}
		if ( ! $s instanceof \stdClass || self::hash( $s->authority ?? null ) !== $request->builder_read_authority_hash || ( $s->snapshot_hash ?? null ) !== self::snapshot_hash( $s ) ) {
			throw new \UnexpectedValueException( 'The admitted native read binding changed.' );
		}
		$found = array();
		foreach ( $request->function_declarations ?? array() as $d ) {
			if ( $d instanceof FunctionDeclaration && $d->getName() === self::FUNCTION_NAME ) {
				$found[] = $d;
			}
		}
		if ( 1 !== count( $found ) || self::json( $found[0]->getParameters() ) !== self::json( self::declaration_parameters( $s ) ) || ( $s->parameters->{'$comment'} ?? null ) !== self::REVISION . ':' . $s->snapshot_hash ) {
			throw new \UnexpectedValueException( 'The admitted native read declaration changed.' );
		}
		return $s;
	}

	public static function managed_call(ModelRequest $request,string $id,string $raw):ModelToolCall {
		$s=self::for_request($request);if(null===$s)throw new \UnexpectedValueException('The read handle was not declared.');
		$args=(new JsonDecoder(65536,32))->decode($raw);$registry=new SchemaRegistry();$schema=clone$s->parameters;unset($schema->{'$comment'});$registry->register('builder.read.handles',self::json($schema));(new ContractValidator($registry))->validate($args,'builder.read.handles');
		$target=$s->targets->{$args->target}??null;if(!$target instanceof \stdClass)throw new \UnexpectedValueException('Unknown native read handle.');
		$input=json_decode(self::json($s->base_input));$input->selection=$target->selection;$input->include_authoring=$args->include_authoring;$input->authoring_components=$args->authoring_components;$input->control_paths=$args->control_paths;
		return new ModelToolCall($id,self::NATIVE_FUNCTION,(object)array('input_json'=>self::json($input)));
	}

	public static function wordpress_call(ModelRequest $request,string $id,$arguments):ModelToolCall {
		return self::managed_call($request,$id,self::json($arguments));
	}

	private static function parameters(object $s):object {
		$conditions=array();
		foreach($s->targets as$key=>$target)if($target->completed_paths)$conditions[]=(object)array('if'=>self::same_component_condition(array($key),$target),'then'=>(object)array('properties'=>(object)array('control_paths'=>(object)array('minItems'=>1,'items'=>(object)array('not'=>(object)array('enum'=>$target->completed_paths))))));
		// One enum per native component, not a repeated copy for every instance.
		foreach($s->control_indexes??array()as$index){$keys=array();foreach($s->targets as$key=>$target)if($target->provider_id===$index->provider_id&&$target->native_id===$index->native_id)$keys[]=$key;if($keys)$conditions[]=(object)array('if'=>self::same_component_condition($keys,$index),'then'=>(object)array('properties'=>(object)array('control_paths'=>(object)array('items'=>(object)array('enum'=>$index->paths)))));}
		$p=(object)array('type'=>'object','properties'=>(object)array('target'=>(object)array('type'=>'string','enum'=>array_keys(get_object_vars($s->targets))),'include_authoring'=>(object)array('type'=>'boolean'),'control_paths'=>(object)array('type'=>'array','maxItems'=>200,'uniqueItems'=>true,'items'=>(object)array('type'=>'string','maxLength'=>256,'pattern'=>'^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$')),'authoring_components'=>(object)array('type'=>'array','maxItems'=>12,'uniqueItems'=>true,'items'=>(object)array('type'=>'object','properties'=>(object)array('provider_id'=>(object)array('type'=>'string','pattern'=>'^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$'),'native_id'=>(object)array('type'=>'string','pattern'=>'^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$')),'required'=>array('provider_id','native_id'),'additionalProperties'=>false))),'required'=>array('target','include_authoring','control_paths','authoring_components'),'additionalProperties'=>false);
		if($conditions)$p->allOf=$conditions;return$p;
	}

	private static function same_component_condition(array $keys,object $identity):object {
		return(object)array('properties'=>(object)array('target'=>(object)array('enum'=>$keys),'include_authoring'=>(object)array('const'=>true),'authoring_components'=>(object)array('items'=>(object)array('enum'=>array((object)array('provider_id'=>$identity->provider_id,'native_id'=>$identity->native_id))))));
	}

	/** Only an untruncated native public index can constrain a component's reads. */
	private static function complete_indexes(array $entries,object $source,array $targets):array {
		$wanted=array();foreach($targets as$target)$wanted[$target->provider_id.'|'.$target->native_id]=true;
		$indexes=array();$blocked=array();
		foreach(array_reverse($entries)as$entry){$d=$entry->response->data??null;$a=$d->authoring??null;
			if(true!==($entry->response->ok??null)||'builder.inspect'!==($entry->response->operation??null)||true!==($d->semantic_report->accepted??null)||'current'!==($d->projection->status??null)||($d->document_key??null)!==($source->document_key??null)||($d->graph_hash??null)!==$source->graph_hash||($d->fact_revision??null)!==$source->fact_revision||($a->semantic_rule_set_revision??null)!==($source->semantic_report->rule_set_revision??null)||($a->catalog_revision??null)!==($source->authoring->catalog_revision??null)||true!==($a->control_index_complete??null)||!is_array($a->control_index??null))continue;
			$seen=array();foreach($a->control_index as$row){if(!$row instanceof \stdClass||!is_string($row->provider_id??null)||!is_string($row->native_id??null))continue;$key=$row->provider_id.'|'.$row->native_id;if(isset($seen[$key])){unset($indexes[$key]);$blocked[$key]=true;continue;}$seen[$key]=true;
				if(isset($indexes[$key],$blocked[$key])||isset($indexes[$key])||isset($blocked[$key])||!isset($wanted[$key]))continue;
				if(!self::public_entity($row)||!is_array($row->paths??null)||!$row->paths||count($row->paths)>200||count($row->paths)!==count(array_unique($row->paths,SORT_REGULAR))){$blocked[$key]=true;continue;}
				$valid=true;foreach($row->paths as$path)if(!is_string($path)||strlen($path)>256||!preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,15}$/D',$path)){$valid=false;break;}
				foreach(is_array($a->components??null)?$a->components:array()as$component)if(($component->provider_id??null)===$row->provider_id&&($component->native_id??null)===$row->native_id){if(!self::public_entity($component))$valid=false;foreach(is_array($component->controls??null)?$component->controls:array()as$control)if(in_array($control->path??null,$row->paths,true)&&!self::public_entity($control))$valid=false;}
				if(!$valid){$blocked[$key]=true;continue;}$index=(object)array('provider_id'=>$row->provider_id,'native_id'=>$row->native_id,'paths'=>array_values($row->paths));$candidate=$indexes;$candidate[$key]=$index;if(strlen(self::json(array_values($candidate)))<=6144)$indexes=$candidate;
			}
		}
		return array_values($indexes);
	}

	private static function snapshot_hash(object $s):string {$v=clone$s;unset($v->snapshot_hash);$v->parameters=clone($v->parameters??new \stdClass());unset($v->parameters->{'$comment'});return self::hash($v);}
	private static function map_hash(object $s):string{$v=clone$s;unset($v->snapshot_hash,$v->parameters,$v->native_schema_hash);return self::hash($v);}
	private static function public_entity($value):bool{if(!$value instanceof \stdClass)return false;foreach(array('private','read_only','writable')as$flag)if(property_exists($value,$flag)&&(!is_bool($value->$flag)||('writable'===$flag?!$value->$flag:$value->$flag)))return false;return true;}
	private static function hash($v):string{return(new JsonCanonicalizer())->digest($v);}
	private static function json($v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION,64);}
}
