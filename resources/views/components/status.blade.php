@props(['status'])
@php($labels=['approved'=>'Approved','accepted'=>'In review','completed'=>'Completed','needs_changes'=>'Needs changes','needs_info'=>'More information needed','pending'=>'Pending review','under_review'=>'Under review','none'=>'Student'])
<span class="status status-{{ $status }}"><span></span>{{ $labels[$status] ?? ucfirst(str_replace('_',' ',$status)) }}</span>
