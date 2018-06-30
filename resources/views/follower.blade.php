<h2>followers</h2>
<div>全部で{{ count($followers) }}件です。</div>
<ul>
<table>
<tr>
<th>id</th>
<th>follower_id</th>
<th>following_id</th>
<th>updated_at</th>
</tr>
  @foreach($followers as $follower)
	<tr>
    <td>{{ $follower['id']}}</td>
    <td>{{ $follower['follower_id']}}</td>
    <td>{{ $follower['following_id']}}</td>
    <td>{{ $follower['updated_at']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 15:49<p />
