<h2>notifications</h2>
<div>全部で{{ count($notifications) }}件です。</div>
<ul>
<table>
<tr>
<th>id</th>
<th>user_id</th>
<th>sender_id</th>
<th>created_at</th>
</tr>
  @foreach($notifications as $notification)
	<tr>
    <td>{{ $notification['id']}}</td>
    <td>{{ $notification['user_id']}}</td>
    <td>{{ $notification['sender_id']}}</td>
    <td>{{ $notification['created_at']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 15:59<p />
