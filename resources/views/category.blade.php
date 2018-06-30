<h2>categories</h2>
<div>全部で{{ count($categories) }}件です。</div>
<ul>
<table>
<tr>
<th>id</th>
<th>parent_id</th>
<th>category_name</th>
<th>created_at</th>
</tr>
  @foreach($categories as $category)
	<tr>
    <td>{{ $category['id']}}</td>
    <td>{{ $category['parent_id']}}</td>
    <td>{{ $category['category_name']}}</td>
    <td>{{ $category['created_at']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 14:49<p />
