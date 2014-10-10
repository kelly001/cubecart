<form action="{$VAL_SELF}" method="post" enctype="multipart/form-data">
    <div id="ZPayment" class="tab_content">
        <h3>{$LANG.zpayment.module_title}</h3>
        <p>{$LANG.zpayment.module_description}</p>
        <fieldset>
            <legend>{$LANG.module.cubecart_settings}</legend>
            <input type="hidden" name="token" value="{$SESSION_TOKEN}" />
            <div><label for="status">{$LANG.common.status}</label><span><input type="hidden" name="module[status]" id="status" class="toggle" value="{$MODULE.status}" /></span></div>
            <div><label for="position">{$LANG.module.position}</label><span><input type="text" name="module[position]" id="position" class="textbox number" value="{$MODULE.position}" /></span></div>
            <div><label for="default">{$LANG.common.default}</label><span><input type="hidden" name="module[default]" id="default" class="toggle" value="{$MODULE.default}" /></span></div>
            <div><label for="description">{$LANG.common.description} *</label><span><input name="module[desc]" id="description" class="textbox" type="text" value="{$MODULE.desc}" /></span></div>
            <div><label for="shop_id">{$LANG.zpayment.shop_id} *</label><span><input name="module[shop_id]" id="shop_id" class="textbox" type="text" value="{$MODULE.shop_id}" /></span></div>
            <div><label for="merchant_key">{$LANG.zpayment.merchant}*</label><span><input name="module[merchant_key]" id="merchant_key" class="textbox" type="text" value="{$MODULE.merchant_key}" /></span></div>
            <div><label for="init_pass">{$LANG.zpayment.pass}</label><span><input name="module[init_pass]" id="init_pass" class="textbox" type="text" value="{$MODULE.init_pass}" /></span></div>
            {$MODULE_ZONES}
        </fieldset>
        <p>{$LANG.module.description_options}</p>
        <div class="form_control">
            <input type="submit" name="save" value="{$LANG.common.save}" />
        </div>
    </div>
</form>