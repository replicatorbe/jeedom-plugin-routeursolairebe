/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function routeursolairebeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

function routeursolairebeCurrentId() {
  var field = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (field && field.value !== '') ? field.value : null
}

function routeursolairebeText(_tag, _text, _className) {
  var element = document.createElement(_tag)
  element.textContent = _text
  if (_className) { element.className = _className }
  return element
}

function routeursolairebeRequest(_data, _success, _failure) {
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/routeursolairebe/core/ajax/routeursolairebe.ajax.php',
    data: _data,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      domUtils.handleAjaxError(request, status, error)
      if (_failure) { _failure() }
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        if (_failure) { _failure() }
        return
      }
      _success(data.result)
    }
  })
}

/* ================================================================ EN CE MOMENT */

var routeursolairebeStatusClass = {
  solar: 'label-warning',
  forced: 'label-danger',
  full: 'label-success',
  waiting: 'label-info',
  idle: 'label-default',
  off: 'label-default',
  alert: 'label-danger'
}

/* Vrai pendant qu'on repose le mode à l'écran : la valeur posée par le script
   émet « change » comme un choix, et renverrait au serveur ce qu'il vient de
   nous dire. */
var routeursolairebeRendering = false

/* Construit en DOM, sans HTML concaténé : les raisons citent des noms
   d'équipements, et un nom contenant une balise ne doit pas s'exécuter. */
function routeursolairebeShowStatus(_status) {
  var root = document.getElementById('div_routeursolairebeStatus')
  if (!root) { return }
  root.innerHTML = ''
  routeursolairebeShowJournal(_status ? _status.journal : null)
  routeursolairebeShowTraces(_status ? _status.traces : null)
  if (_status && _status.mode) {
    routeursolairebeRendering = true
    document.getElementById('sel_routeursolairebeMode').value = _status.mode
    routeursolairebeRendering = false
  }
  if (!_status || !_status.state) {
    root.appendChild(routeursolairebeText('span', '{{Pas encore de décision : enregistrez l\'équipement, ou cliquez sur « Évaluer maintenant ».}}'))
    return
  }
  var head = document.createElement('div')
  var label = routeursolairebeText('span', _status.state, 'label ' + (routeursolairebeStatusClass[_status.status] || 'label-default'))
  label.style.fontSize = '14px'
  head.appendChild(label)
  if (_status.simulation == 1) {
    var sim = routeursolairebeText('span', '{{Simulation}}', 'label label-primary')
    sim.style.marginLeft = '6px'
    sim.title = '{{Boiler virtuel : aucun ordre n\'est envoyé}}'
    head.appendChild(sim)
  }
  head.appendChild(routeursolairebeText('span', '  {{à}} ' + _status.at, 'text-muted'))
  root.appendChild(head)

  var reason = routeursolairebeText('p', _status.reason)
  reason.style.margin = '8px 0'
  root.appendChild(reason)

  var table = document.createElement('table')
  table.className = 'table table-condensed'
  table.style.marginBottom = '6px'
  _status.rows.forEach(function (row) {
    var tr = document.createElement('tr')
    tr.appendChild(routeursolairebeText('th', row[0]))
    tr.appendChild(routeursolairebeText('td', row[1]))
    table.appendChild(tr)
  })
  root.appendChild(table)
  if (_status.yesterday) {
    root.appendChild(routeursolairebeText('p', _status.yesterday, 'text-muted'))
  }
}

var routeursolairebeJournalIcons = {
  on: 'fa-fire text-warning',
  off: 'fa-power-off',
  full: 'fa-check-circle text-success',
  day: 'fa-calendar-check',
  mode: 'fa-hand-pointer'
}

function routeursolairebeShowJournal(_entries) {
  var root = document.getElementById('div_routeursolairebeJournal')
  if (!root) { return }
  root.innerHTML = ''
  if (!_entries || _entries.length === 0) {
    root.appendChild(routeursolairebeText('span', '{{Aucune décision pour le moment.}}', 'text-muted'))
    return
  }
  var list = document.createElement('table')
  list.className = 'table table-condensed'
  _entries.forEach(function (entry) {
    var tr = document.createElement('tr')
    var when = routeursolairebeText('td', entry.when, 'text-muted')
    when.style.whiteSpace = 'nowrap'
    tr.appendChild(when)
    var icon = document.createElement('i')
    icon.className = 'fas ' + (routeursolairebeJournalIcons[entry.kind] || 'fa-info-circle')
    var iconCell = document.createElement('td')
    iconCell.appendChild(icon)
    tr.appendChild(iconCell)
    tr.appendChild(routeursolairebeText('td', entry.text))
    list.appendChild(tr)
  })
  root.appendChild(list)
}

function routeursolairebeShowTraces(_traces) {
  var select = document.getElementById('sel_routeursolairebeTrace')
  if (!select) { return }
  var previous = select.value
  select.innerHTML = ''
  ;(_traces || []).forEach(function (name) {
    var option = document.createElement('option')
    option.value = name
    option.textContent = (name == 'journees') ? '{{Bilan des journées}}' : name
    select.appendChild(option)
  })
  if (previous) { select.value = previous }
}

function routeursolairebeLoadStatus(_refresh) {
  var id = routeursolairebeCurrentId()
  if (id === null) { return }
  routeursolairebeRequest({ action: 'status', id: id, refresh: _refresh ? 1 : 0 }, function (result) {
    routeursolairebeShowStatus(result)
  })
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

var routeursolairebeTimer = null

function printEqLogic(_eqLogic) {
  routeursolairebeShowStatus(null)
  clearInterval(routeursolairebeTimer)
  var saved = isset(_eqLogic.id) && _eqLogic.id != ''
  document.getElementById('sel_routeursolairebeMode').disabled = !saved
  if (!saved) { return }
  routeursolairebeLoadStatus(false)
  /* Le panneau suit le routeur tant que la page est ouverte : le compteur
     publie toutes les 10 secondes, le relais peut basculer à tout moment. */
  routeursolairebeTimer = setInterval(function () {
    if (!document.getElementById('div_routeursolairebeStatus')) {
      clearInterval(routeursolairebeTimer)
      return
    }
    if (document.querySelector('.eqLogic').offsetParent !== null) {
      routeursolairebeLoadStatus(false)
    }
  }, 15000)
}

/* ================================================================ COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  /* Le champ caché « id » n'est pas décoratif : sans lui, chaque sauvegarde
     détruit et recrée les commandes — historique perdu, scénarios cassés. */
  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (_cmd.type == 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : les écouteurs sont posés sur le conteneur
   de page, qui est remplacé à chaque navigation et les emporte avec lui. */
var routeursolairebeContainer = document.getElementById('div_pageContainer') || document.body

routeursolairebeContainer.addEventListener('click', function (event) {
  var target
  if (target = event.target.closest('.rsPickCmd')) {
    var field = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + target.getAttribute('data-key') + '"]')
    jeedom.cmd.getSelectModal({ cmd: { type: target.getAttribute('data-type') } }, function (result) {
      if (!result || !result.human) { return }
      field.value = result.human
      routeursolairebeMarkModified()
    })
    return
  }
  if (target = event.target.closest('.rsClearCmd')) {
    var cleared = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + target.getAttribute('data-key') + '"]')
    if (cleared && cleared.value !== '') {
      cleared.value = ''
      routeursolairebeMarkModified()
    }
    return
  }
  if (event.target.closest('#bt_routeursolairebeRefresh')) {
    routeursolairebeLoadStatus(true)
    return
  }
  /* La trace arrive en texte et repart en fichier, sans passer par une URL
     du dossier data, que le .htaccess ferme au navigateur. */
  if (event.target.closest('#bt_routeursolairebeTrace')) {
    var id = routeursolairebeCurrentId()
    var name = document.getElementById('sel_routeursolairebeTrace').value
    if (id === null || !name) { return }
    routeursolairebeRequest({ action: 'trace', id: id, name: name }, function (result) {
      var blob = new Blob(['﻿' + result.csv], { type: 'text/csv;charset=utf-8' })
      var link = document.createElement('a')
      link.href = URL.createObjectURL(blob)
      link.download = 'routeur-solaire-' + result.name + '.csv'
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTimeout(function () { URL.revokeObjectURL(link.href) }, 1000)
    })
    return
  }
})

routeursolairebeContainer.addEventListener('change', function (event) {
  if (event.target.id == 'sel_routeursolairebeMode') {
    if (routeursolairebeRendering) { return }
    var id = routeursolairebeCurrentId()
    if (id === null) { return }
    routeursolairebeRequest({ action: 'mode', id: id, mode: event.target.value }, function (result) {
      routeursolairebeShowStatus(result)
    }, function () { routeursolairebeLoadStatus(false) })
  }
})
