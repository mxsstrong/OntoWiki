<!-- ========== Info from phpDoc block ========= -->
{if $desc}
<p class="description">{$desc}</p>
{/if}
{if $tags}
	<ul class="tags">
		{section name=tags loop=$tags}
		<li><span class="field">{$tags[tags].keyword}:</span> {$tags[tags].data}</li>
		{/section}
	</ul>
{/if}
