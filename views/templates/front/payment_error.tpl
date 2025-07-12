<div class="alert alert-danger">
    <h4>{l s='Payment Error' mod='freedompay'}</h4>
    
    {if !empty($errors)}
        {foreach from=$errors item=error}
            <p>{$error}</p>
        {/foreach}
    {else}
        <p>{l s='An unknown error occurred during payment processing' mod='freedompay'}</p>
    {/if}
    
    <p class="debug-info">
        {l s='Please contact support and provide this reference:'} 
        <strong>{$smarty.now|date_format:'%Y%m%d-%H%M%S'}</strong>
    </p>
    <p>
        <a href="{$link->getPageLink('order', true, NULL, ['step' => 3])|escape:'html'}" class="btn btn-primary">
            {l s='Return to checkout' mod='freedompay'}
        </a>
    </p>
</div>