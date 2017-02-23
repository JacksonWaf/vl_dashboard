@extends('layout')

@section('content')
<link href="{{ asset('/css/vl2.css') }}" rel="stylesheet">
<?php 
// if($tab=='roche'){
//     $roche_actv="class=active";
//     $abbott_actv="";
//     $released_actv="";
// }elseif($tab=='abbott'){
//     $abbott_actv="class=active";
//     $roche_actv="";
//     $released_actv="";
// }else{
    $abbott_actv="";
    $roche_actv="";
    $released_actv="class=active";
//}

// $roche_url = "/lab_qc/index?tab=roche";
// $abbott_url = "/lab_qc/index?tab=abbott";
$released_url = "/qc?tab=released";
?>


<ul id="tabs" class="nav nav-tabs" data-tabs="tabs">
    <li {{ $released_actv }} title='Released'><a href="{!! $released_url !!}" >Released Worksheets</a></li>
</ul>
{!! Form::open(array('url'=>'/result','id'=>'view_form', 'name'=>'view_form', 'target' => 'Map' )) !!}

<div id="my-tab-content" class="tab-content">
    <div class="tab-pane active" id="print"> 
        <table id="results-table" class="table table-condensed table-bordered  table-striped" style="max-width:1100px;margin-top:10px">
            <thead>
                <tr>
                    <th>Worksheet Number</th> 
                    <th>Date time</th> 
                    <th>Created by</th>             
                </tr>
            </thead>
        </table>
    </div>
</div>
  

{!! Form::close() !!}
<script type="text/javascript">

$('#qc').addClass('active');

$(function() {
    $('#results-table').DataTable({

        processing: true,
        serverSide: true,
        pageLength: 50,
        ajax: '{!! url("/qc/data?tab=released") !!}',
        columns: [
            {data: 'worksheetReferenceNumber', name: 'worksheetReferenceNumber'},
            {data: 'created', name: 'w.created'},
            {data: 'createdby', name: 'w.createdby'}, 
        ]
    });
});

</script>
@endsection()