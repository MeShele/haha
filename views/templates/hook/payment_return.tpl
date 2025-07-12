<div class="box">
    <h3>{l s='Your order is complete' mod='freedompay'}</h3>
    
    <p>
        {l s='Order reference:' mod='freedompay'} 
        <strong>{$reference|escape:'html':'UTF-8'}</strong>
    </p>
    
    <p>
        {l s='Amount paid:' mod='freedompay'} 
        <strong>{$total|escape:'html':'UTF-8'}</strong>
    </p>
    
    <p>
        {l s='We have successfully received your payment via FreedomPay.' mod='freedompay'}
    </p>
    
    <p>
        {l s='For any questions, please contact our' mod='freedompay'} 
        <a href="{$contact_url}">{l s='customer support' mod='freedompay'}</a>
    </p>
    
    <p>
        <a href="{$link->getPageLink('history', true)}" class="btn btn-primary">
            {l s='View your order history' mod='freedompay'}
        </a>
    </p>
</div>