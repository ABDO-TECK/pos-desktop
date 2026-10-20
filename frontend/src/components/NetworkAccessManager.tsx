import { useEffect } from 'react'
import useNetworkAccessStore from '../store/networkAccessStore'

export default function NetworkAccessManager() {
  const initialize = useNetworkAccessStore(state => state.initialize)
  useEffect(() => { void initialize() }, [initialize])
  return null
}
