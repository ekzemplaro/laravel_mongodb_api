<h2>bookmarks</h2>
<div>全部で{{ count($bookmarks) }}件です。</div>
<ul>
<table>
<tr>
<th>id</th>
<th>topic_id</th>
<th>user_id</th>
<th>created_at</th>
</tr>
  @foreach($bookmarks as $bookmark)
	<tr>
    <td>{{ $bookmark['id']}}</td>
    <td>{{ $bookmark['topic_id']}}</td>
    <td>{{ $bookmark['user_id']}}</td>
    <td>{{ $bookmark['created_at']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 15:36<p />
