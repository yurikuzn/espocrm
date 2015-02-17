{{#unless onlyContent}}
<li data-id="{{model.id}}" class="list-group-item">
{{/unless}}

    {{#unless noEdit}}
    <div class="pull-right right-container">
    {{{right}}}
    </div>
    {{/unless}}

    <div class="stream-head-container">
        <div class="pull-left">
            {{{avatar}}}
        </div>
        <div class="stream-head-text-container">
            <span class="text-muted"><span class="glyphicon glyphicon-envelope "></span>
                {{{message}}}
            </span>
        </div>
    </div>


    {{#if post}}
    <div class="stream-post-container">
        <span class="cell cell-post">{{{post}}}</span>
    </div>
    {{/if}}

    {{#if attachments}}
    <div class="stream-attachments-container">
        <span class="cell cell-attachments">{{{attachments}}}</span>
    </div>
    {{/if}}

    <div class="stream-date-container">
        <span class="text-muted small">{{{createdAt}}}</span>
    </div>

{{#unless onlyContent}}
</li>
{{/unless}}
