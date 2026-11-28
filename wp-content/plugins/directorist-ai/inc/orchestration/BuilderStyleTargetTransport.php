<?php
/** Paired model-only native style targets; the original proposal guards still apply. */
namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\{ContractValidator,JsonCanonicalizer,JsonDecoder,SchemaRegistry};
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/** No target is inferred, repaired or promoted from a partial authoring receipt. */
final class BuilderStyleTargetTransport {
	public const FUNCTION_NAME='directorist_ai_style_current_areas';
	public const ABILITY_NAME='directorist-ai/builder-draft-propose';
	public const NATIVE_FUNCTION='wpab__directorist-ai__builder-draft-propose';
	private const REVISION='builder-style-targets-v1';

	/** Exact complete native schemas/known values must already reach this model. */
	public static function capture(array $entries,array $projected,object $binding,object $read):?object {
		$c=new JsonCanonicalizer();$controls=array();
		foreach(array_reverse($entries)as$entry){
			$proof=BuilderControlHandoff::visible($entry,$projected,$binding->builder_selection??null);
			if(null===$proof||$proof->source->graph_hash!==$read->source->graph_hash||$proof->source->fact_revision!==$read->source->fact_revision||$proof->source->catalog_revision!==$read->source->catalog_revision||$proof->source->semantic_rule_set_revision!==$read->source->rule_set_revision)continue;
			foreach($read->targets as$key=>$target){
				if($target->node_id!==$proof->node_id||$c->encode($target->locator)!==$c->encode($proof->locator))continue;
				foreach($proof->paths as$path){$pair=$key.'|'.$path;if(isset($controls[$pair]))continue;$controls[$pair]=(object)array('target'=>$key,'node_id'=>$proof->node_id,'provider_id'=>$proof->provider_id,'native_id'=>$proof->native_id,'locator'=>$proof->locator,'scope'=>$proof->scope,'control_path'=>$path,'value_schema'=>(new BuilderPromptSchema())->compact($proof->schemas->$path),'known_value_hash'=>$c->digest($proof->values->$path));}
			}
		}
		if(!$controls||count($controls)>24)return null;
		$mode='page'===($read->base_input->document->native_kind??null)?'private_page_draft':'private_template_draft';
		$s=(object)array('revision'=>self::REVISION,'authority'=>$read->authority,'read_map_hash'=>self::read_hash($read),'source'=>$read->source,'base_input'=>(object)array('document'=>$read->base_input->document,'directory_id'=>$read->base_input->directory_id,'scope'=>$read->base_input->scope,'intent'=>$mode),'controls'=>array_values($controls));
		try{(new JsonDecoder(32768,48))->check($s);self::prepare($s,self::hash($s->authority),array('type'=>'object'));}catch(\Throwable$error){return null;}return$s;
	}

	/** Freeze the declaration plus exact native propose contract at admission. */
	public static function prepare($value,string $authority_hash,array $native):object {
		if(is_string($value))$value=(new JsonDecoder(32768,48))->decode($value);
		if(!$value instanceof \stdClass||self::REVISION!==($value->revision??null)||!($value->authority??null)instanceof \stdClass||self::hash($value->authority)!==$authority_hash||!is_array($value->controls??null)||!$value->controls||count($value->controls)>24)throw new \UnexpectedValueException('Current paired style targets are unavailable.');
		$native_hash=self::hash(json_decode(self::json($native)));
		if(isset($value->snapshot_hash)){
			if(($value->native_schema_hash??null)!==$native_hash||$value->snapshot_hash!==self::snapshot_hash($value)||($value->parameters->{'$comment'}??null)!==self::REVISION.':'.$value->snapshot_hash)throw new \UnexpectedValueException('The admitted paired native style contract changed.');
			return json_decode(self::json($value));
		}
		$s=json_decode(self::json($value));$definitions=new \stdClass();$branches=array();
		foreach($s->controls as$at=>$control){
			if(!($control->value_schema??null)instanceof \stdClass||!is_string($control->target??null)||!is_string($control->control_path??null)||!($control->locator??null)instanceof \stdClass||!($control->scope??null)instanceof \stdClass)throw new \UnexpectedValueException('The native style pair is incomplete.');
			$embedded=BuilderOneNodeFactoryBinding::embed_schema($control->value_schema,'style'.$at);
			if(null===$embedded)throw new \UnexpectedValueException('The exact native style value schema cannot be embedded.');
			foreach($embedded->definitions as$name=>$schema){if(isset($definitions->$name))throw new \UnexpectedValueException('Native style schema definitions overlap.');$definitions->$name=$schema;}
			$branches[]=(object)array('type'=>'object','description'=>'Current '.$control->native_id.' target '.$control->target.'. Match its saved heading/region roles in builder_read_targets to the user goal. Readiness alone never identifies the intended region.','properties'=>(object)array('target'=>(object)array('type'=>'string','const'=>$control->target),'control_path'=>(object)array('type'=>'string','const'=>$control->control_path),'value'=>$embedded->schema),'required'=>array('target','control_path','value'),'additionalProperties'=>false);
			$control->value_schema_hash=self::hash($control->value_schema);unset($control->value_schema);
		}
		$divi='divi'===($s->base_input->document->builder??null);
		$s->parameters=(object)array('type'=>'object','properties'=>(object)array('edits'=>(object)array('type'=>'array','minItems'=>1,'maxItems'=>$divi?8:10,'items'=>(object)array('oneOf'=>$branches))),'required'=>array('edits'),'additionalProperties'=>false);
		if($divi){$owners=array_unique(array_column($s->controls,'target'));$one=array();foreach($owners as$owner)$one[]=(object)array('properties'=>(object)array('edits'=>(object)array('items'=>(object)array('properties'=>(object)array('target'=>(object)array('const'=>$owner))))));$s->parameters->allOf=array((object)array('oneOf'=>$one));}
		if(get_object_vars($definitions))$s->parameters->definitions=$definitions;
		$s->native_schema_hash=$native_hash;$s->snapshot_hash=self::snapshot_hash($s);$s->parameters->{'$comment'}=self::REVISION.':'.$s->snapshot_hash;(new JsonDecoder(32768,48))->check($s);return$s;
	}

	public static function declaration(object $snapshot):FunctionDeclaration {
		return new FunctionDeclaration(self::FUNCTION_NAME,'Prepare reversible native set_control changes on the EXACT paired current target/group. Choose the intended region by its saved heading/role in builder_read_targets, not merely by readiness. If that target/group is missing, inspect it first. Use direct edits with target/control_path/value, never input_json, locators, hashes, CSS selectors or a different operation. Native schema/ownership/source guards and the normal editor review/save still apply.',(array)$snapshot->parameters);
	}

	public static function instruction(?object $snapshot=null): string {
		$note=' The declared directorist_ai_style_current_areas tool is the exact paired handoff to native builder.draft_propose for this request. Use direct edits with the matching target handle, control_path and native schema value. Do not call the raw native proposal function or copy locators/hashes. Match the user\'s intended region to the current saved heading/role hints. Readiness alone does not identify the intended region: inspect its missing contract rather than substitute another ready area. This changes only model transport; every original native permission, source, scope, style-intent, schema and editor apply/save guard remains required.';
		if('divi'!==($snapshot->base_input->document->builder??null))return$note;
		$paths=array();foreach($snapshot->controls??array()as$control)if(($control->value_schema??null)instanceof \stdClass&&self::phone_schema($control->value_schema))$paths[]=$control->control_path;
		$note.=' The current native Divi serializer supports at most eight controls on ONE saved module and exact scope per proposal. Do not batch a Section with its Row or different modules. This native invariant is also enforced in the paired schema. For a phone request, use a native responsive GROUP with its declared phone destination; a .desktop. scalar changes desktop and is not a substitute for phone. Preserve desktop/unrequested values and retain the phone objective after a rejection. If the intended module\'s responsive group is missing, inspect that exact registered group first; do not switch to desktop changes or another ready region.';
		if($paths)$note.=' These actually inspected schemas declare a phone branch: '.implode(', ',array_slice(array_unique($paths),0,8)).'.';
		return$note;
	}

	/** Follow only reachable local schema branches; never infer a phone property. */
	private static function phone_schema(object $root):bool {
		$walk=function(object $s,int $depth,array $seen=array())use(&$walk,$root):bool{
			if($depth>24)return false;if(isset($s->{'$ref'})){if(!is_string($s->{'$ref'})||isset($seen[$s->{'$ref'}])||!preg_match('/^#\/(definitions|\$defs)\/([A-Za-z_][A-Za-z0-9_-]*)$/D',$s->{'$ref'},$m))return false;$seen[$s->{'$ref'}]=true;$v=$root->{$m[1]}->{$m[2]}??null;return$v instanceof \stdClass&&$walk($v,$depth+1,$seen);}
			if(($s->properties->phone??null)instanceof \stdClass)return true;foreach($s->properties??array()as$v)if($v instanceof \stdClass&&$walk($v,$depth+1,$seen))return true;foreach(array('oneOf','anyOf','allOf')as$key)foreach($s->$key??array()as$v)if($v instanceof \stdClass&&$walk($v,$depth+1,$seen))return true;return false;};return$walk($root,0);
	}

	public static function for_request(ModelRequest $r):?object {
		$s=$r->builder_style_targets;if(null===$s)return null;
		if($r->builder_initial_layout||null!==$r->visitor_plan_function||'set_control'!==$r->builder_change_operation||!in_array(self::ABILITY_NAME,$r->ability_names,true))throw new \UnexpectedValueException('The paired native proposal ability is not admitted.');
		$read=BuilderReadTargetTransport::for_request($r);
		if(null===$read||!$s instanceof \stdClass||self::hash($s->authority??null)!==$r->builder_read_authority_hash||($s->read_map_hash??null)!==self::read_hash($read)||($s->snapshot_hash??null)!==self::snapshot_hash($s))throw new \UnexpectedValueException('The admitted native style target binding changed.');
		$found=array();foreach($r->function_declarations??array()as$d)if($d instanceof FunctionDeclaration&&$d->getName()===self::FUNCTION_NAME)$found[]=$d;
		if(count($found)!==1||self::json($found[0]->getParameters())!==self::json((array)$s->parameters)||($s->parameters->{'$comment'}??null)!==self::REVISION.':'.$s->snapshot_hash)throw new \UnexpectedValueException('The paired style declaration changed.');return$s;
	}

	/** Convert only a fully valid model batch, preserving exact original native inputs. */
	public static function managed_call(ModelRequest $r,string $id,string $raw):ModelToolCall {
		$s=self::for_request($r);if(null===$s)throw new \UnexpectedValueException('No paired native style declaration.');
		$a=(new JsonDecoder(65536,48))->decode($raw);$schema=clone$s->parameters;unset($schema->{'$comment'});$registry=new SchemaRegistry();$registry->register('builder.style.pairs',self::json($schema));(new ContractValidator($registry))->validate($a,'builder.style.pairs');
		$input=json_decode(self::json($s->base_input));$input->request_id='style-'.substr(self::hash((object)array('authority'=>$s->authority,'edits'=>$a->edits)),0,58);$input->changes=array();$seen=array();
		foreach($a->edits as$edit){$pair=$edit->target.'|'.$edit->control_path;if(isset($seen[$pair]))throw new \UnexpectedValueException('A native control pair is repeated.');$seen[$pair]=true;$found=null;foreach($s->controls as$control)if($control->target===$edit->target&&$control->control_path===$edit->control_path){$found=$control;break;}if(null===$found)throw new \UnexpectedValueException('Unknown paired native control.');$input->changes[]=(object)array('op'=>'set_control','target'=>$found->locator,'control_path'=>$found->control_path,'value'=>$edit->value,'scope'=>$found->scope);}
		return new ModelToolCall($id,self::NATIVE_FUNCTION,(object)array('input_json'=>self::json($input)));
	}
	public static function wordpress_call(ModelRequest $r,string $id,$arguments):ModelToolCall{return self::managed_call($r,$id,self::json($arguments));}
	private static function read_hash(object $s):string{$v=clone$s;unset($v->snapshot_hash,$v->parameters,$v->native_schema_hash);return self::hash($v);}
	private static function snapshot_hash(object $s):string{$v=clone$s;unset($v->snapshot_hash);$v->parameters=clone($v->parameters??new \stdClass());unset($v->parameters->{'$comment'});return self::hash($v);}
	private static function hash($v):string{return(new JsonCanonicalizer())->digest($v);}
	private static function json($v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION,64);}
}
