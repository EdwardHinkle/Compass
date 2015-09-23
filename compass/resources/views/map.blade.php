@extends('layouts.map')

@section('content')

<div class="corner-logo"><a href="/"><img src="/assets/compass.svg" height="40"/></a></div>

<div id="calendar">
  <div class="scroll">
  <?php
  $days = array_fill(1,31,['#']);
  $start = new DateTime('2008-05-30T00:00:00-0800');
  $end = new DateTime();
  $end->setTimeZone(new DateTimeZone('America/Los_Angeles'));
  $i = clone $start;
  while((int)$i->format('Y') <= (int)$end->format('Y') && (int)$i->format('M') <= (int)$end->format('M')) {
    ?>
    @include('partials/calendar', [
      'year' => $i->format('Y'),
      'month' => $i->format('m'),
      'days' => $days,
      'day_name_length' => 3,
      'month_href' => null,
      'first_day' => 1,
      'pn' => []
    ])
    <?php
    $i = $i->add(new DateInterval('P1M'));
  }
  ?>
  </div>
</div>

<div id="map"></div>

<div id="database" data-name="{{ $database->name }}" data-token="{{ $database->read_token }}"></div>

<script>
jQuery(function($){
  $(".calendar a[data-date='{{ date('Y-m-d') }}']").focus().click();
});
</script>
@endsection
