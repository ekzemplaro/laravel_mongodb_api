<h2>comments</h2>
<div>全部で{{ count($comments) }}件です。</div>
<ul>
<table>
<tr>
<th>id</th>
<th>root_comment_id</th>
<th>topic_id</th>
<th>description</th>
</tr>
  @foreach($comments as $comment)
	<tr>
    <td>{{ $comment['id']}}</td>
    <td>{{ $comment['root_comment_id']}}</td>
    <td>{{ $comment['topic_id']}}</td>
    <td>{{ $comment['description']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 14:54<p />
