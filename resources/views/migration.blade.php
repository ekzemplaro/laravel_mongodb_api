<h2>migrations</h2>
<div>全部で{{ count($migrations) }}件です。</div>
<ul>
<table>
<tr>
<th>name</th>
<th>batch</th>
</tr>
  @foreach($migrations as $migration)
	<tr>
    <td>{{ $migration['name']}}</td>
    <td>{{ $migration['batch']}}</td>
	</tr>
  @endforeach
</table >
<p />
Jun/15/2018 PM 15:48<p />
