<!--
 - @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 -
 - @author Joas Schilling <coding@schilljs.com>
 -
 - @license GNU AGPL version 3 or any later version
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -
 -->

<template>
	<div class="turn-server">
		<input ref="turn_server" type="text" name="turn_server"
			placeholder="turnserver:port" :value="server" :disabled="loading"
			:aria-label="t('spreed', 'TURN server URL')" @input="updateServer">
		<input ref="turn_secret" type="text" name="turn_secret"
			placeholder="secret" :value="secret" :disabled="loading"
			:aria-label="t('spreed', 'TURN server secret')" @input="updateSecret">

		<select class="protocols" :value="protocols" :disabled="loading"
			:aria-label="t('spreed', 'TURN server protocols')" @input="updateProtocols">
			<option value="udp,tcp">
				{{ t('spreed', 'UDP and TCP') }}
			</option>
			<option value="udp">
				{{ t('spreed', 'UDP only') }}
			</option>
			<option value="tcp">
				{{ t('spreed', 'TCP only') }}
			</option>
		</select>

		<a v-show="!loading" v-tooltip.auto="t('spreed', 'Test this server')" class="icon"
			:class="{'icon-category-monitoring': !testing && !testingError && !testingSuccess, 'icon-loading-small': testing, 'icon-error': testingError, 'icon-checkmark': testingSuccess}"
			@click="testServer" />
		<a v-show="!loading" v-tooltip.auto="t('spreed', 'Delete this server')" class="icon icon-delete"
			@click="removeServer" />
	</div>
</template>

<script>
import { Tooltip } from 'nextcloud-vue'
import hmacSHA1 from 'crypto-js/hmac-sha1'
import Base64 from 'crypto-js/enc-base64'
import debounce from 'debounce'

export default {
	name: 'TurnServer',

	directives: {
		tooltip: Tooltip
	},

	props: {
		server: {
			type: String,
			default: '',
			required: true
		},
		secret: {
			type: String,
			default: '',
			required: true
		},
		protocols: {
			type: String,
			default: '',
			required: true
		},
		index: {
			type: Number,
			default: -1,
			required: true
		},
		loading: {
			type: Boolean,
			default: false
		}
	},

	data: () => {
		return {
			testing: {
				type: Boolean,
				default: false
			},
			testingError: {
				type: Boolean,
				default: false
			},
			testingSuccess: {
				type: Boolean,
				default: false
			}
		}
	},

	mounted() {
		this.testing = false
		this.testingError = false
		this.testingSuccess = false
	},

	methods: {
		debounceTestServer: debounce(function() {
			this.testServer()
		}, 1000),

		testServer() {
			this.testing = true
			this.testingError = false
			this.testingSuccess = false

			var protocols = this.protocols.split(',')
			if (!this.server || !this.secret || !protocols.length) {
				return
			}

			const urls = []
			let i
			for (i = 0; i < protocols.length; i++) {
				urls.push('turn:' + this.server + '?transport=' + protocols[i])
			}

			var expires = Math.round((new Date()).getTime() / 1000) + (5 * 60)
			var username = expires + ':turn-test-user'
			var password = Base64.stringify(hmacSHA1(username, this.secret))

			var iceServer = {
				username: username,
				credential: password,
				urls: urls
			}

			// Create a PeerConnection with no streams, but force a m=audio line.
			var config = {
				iceServers: [
					iceServer
				],
				iceTransportPolicy: 'relay'
			}
			var offerOptions = {
				offerToReceiveAudio: 1
			}
			console.info('Creating PeerConnection with', config)
			var candidates = []

			var pc = new RTCPeerConnection(config)
			var timeout = setTimeout(function() {
				this.notifyTurnResult(candidates, timeout)
				pc.close()
			}.bind(this), 10000)
			pc.onicecandidate = this.iceCallback.bind(this, pc, candidates, timeout)
			pc.onicegatheringstatechange = this.gatheringStateChange.bind(this, pc, candidates, timeout)
			pc.createOffer(
				offerOptions
			).then(
				function(description) {
					pc.setLocalDescription(description)
				},
				function(error) {
					console.error('Error creating offer', error)
					this.notifyTurnResult(candidates, timeout)
					pc.close()
				}.bind(this)
			)
		},

		iceCallback(pc, candidates, timeout, e) {
			if (e.candidate) {
				candidates.push(this.parseCandidate(e.candidate.candidate))
			} else if (!('onicegatheringstatechange' in RTCPeerConnection.prototype)) {
				pc.close()
				this.notifyTurnResult(candidates, timeout)
			}
		},

		notifyTurnResult(candidates, timeout) {
			console.info('Received candidates', candidates)

			var types = candidates.map((cand) => cand.type)

			this.testing = false
			if (types.indexOf('relay') === -1) {
				this.testingError = true
			} else {
				this.testingSuccess = true
			}

			setTimeout(() => {
				this.testingError = false
				this.testingSuccess = false
			}, 3000)

			clearTimeout(timeout)
		},

		// Parse a candidate:foo string into an object, for easier use by other methods.
		parseCandidate(text) {
			const candidateStr = 'candidate:'
			const pos = text.indexOf(candidateStr) + candidateStr.length
			const parts = text.substr(pos).split(' ')

			return {
				component: parts[1],
				type: parts[7],
				foundation: parts[0],
				protocol: parts[2],
				address: parts[4],
				port: parts[5],
				priority: parts[3]
			}
		},

		gatheringStateChange(pc, candidates, timeout) {
			if (pc.iceGatheringState !== 'complete') {
				return
			}

			pc.close()
			this.notifyTurnResult(candidates, timeout)
		},

		removeServer() {
			this.$emit('removeServer', this.index)
		},
		updateServer(event) {
			this.$emit('update:server', event.target.value)
			this.debounceTestServer()
		},
		updateSecret(event) {
			this.$emit('update:secret', event.target.value)
			this.debounceTestServer()
		},
		updateProtocols(event) {
			this.$emit('update:protocols', event.target.value)
			this.debounceTestServer()
		}
	}
}
</script>

<style lang="scss" scoped>
.turn-server {
	height: 44px;
	display: flex;
	align-items: center;
}
</style>
